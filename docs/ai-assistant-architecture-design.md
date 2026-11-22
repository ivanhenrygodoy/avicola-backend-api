# 🏗️ GUÍA MAESTRA Y BLUEPRINT TÉCNICO: ARQUITECTURA INTEGRAL DE ASISTENTES DE IA EMPRESARIALES
> **Propósito del Documento:** Servir como guía pedagógica de aprendizaje y a la vez como especificación técnica reutilizable para nuevos desarrollos. Cuando se inicie un nuevo proyecto con requerimientos de IA conversacional, este blueprint define la arquitectura completa, modelos de datos, flujos de seguridad, observabilidad y componentes de interfaz requeridos.

---

## 0. Filosofía de Diseño y Marco Conceptual

Un asistente de inteligencia artificial en una aplicación de negocio **nunca debe ser un LLM conectado directamente a una base de datos**. Un modelo de lenguaje es un motor de inferencia probabilístico y creativo; un sistema empresarial requiere garantías deterministas, seguridad, confidencialidad, auditoría y control de costos.

Por ello, el asistente se construye mediante un **Harness (Arnés de Control)**: una arquitectura de software en capas donde el modelo de lenguaje actúa únicamente como intérprete de lenguaje natural y planificador de tareas, mientras el código de la aplicación gobierna la seguridad, la ejecución de herramientas, la persistencia y la presentación.

```
┌─────────────────────────────────────────────────────────────────────────────────┐
│                           FILOSOFÍA DEL SISTEMA (HARNESS)                       │
├────────────────────────────────┬────────────────────────────────────────────────┤
│ 1. Zero-Hallucination          │ El LLM no inventa datos; su única fuente de   │
│    (Cero Invención)            │ verdad son las herramientas (Tools) del arnés. │
├────────────────────────────────┼────────────────────────────────────────────────┤
│ 2. Código > Prompt             │ Las reglas de seguridad y dominio se respaldan │
│    (Garantías Deterministas)   │ en Guardrails en código, no solo en prompts.   │
├────────────────────────────────┼────────────────────────────────────────────────┤
│ 3. Seguridad Anti-IDOR         │ La identidad del usuario viaja en contexto     │
│    (Aislamiento de Sesión)     │ inmutable del servidor; el LLM jamás la maneja.│
├────────────────────────────────┼────────────────────────────────────────────────┤
│ 4. Payloads Compactos          │ Las herramientas devuelven agregaciones SQL    │
│    (Economía de Tokens)        │ ligeras (50–150 tokens), no volcados masivos.  │
├────────────────────────────────┼────────────────────────────────────────────────┤
│ 5. Arquitectura Abierta (OCP)  │ Nuevas tools o guardrails se agregan sin tocar │
│    (Open-Closed Principle)     │ el orquestador principal.                      │
├────────────────────────────────┼────────────────────────────────────────────────┤
│ 6. Observabilidad Fail-Safe    │ La auditoría es privada y si falla, jamás      │
│    (Resiliencia de Chat)       │ interrumpe la respuesta del usuario.           │
└────────────────────────────────┴────────────────────────────────────────────────┘
```

---

## 1. Arquitectura del Harness y Orquestación Central

El núcleo del asistente es el **Orquestador**, encargado de sincronizar el ciclo de vida de cada turno: desde que el usuario envía su mensaje hasta que recibe la respuesta final.

### 1.1 Diagrama de Arquitectura del Orquestador

```mermaid
sequenceDiagram
    autonumber
    actor U as Usuario / Frontend
    participant Ctrl as Controlador HTTP (Delgado)
    participant Orq as Orquestador (Cerebro)
    participant GP as Pipeline de Guardrails
    participant Prompt as PromptComposer
    participant LLM as Cliente LLM (Gemini / OpenAI)
    participant Reg as ToolRegistry
    participant Tool as Tool de Dominio
    participant Log as Servicio de Benchmark / Auditoría

    U->>Ctrl: POST /asistente/chat { mensaje, uuid_conversacion }
    Ctrl->>Ctrl: Valida sesión Sanctum + Inyecta $contextoFijo (id_usuario, rol)
    Ctrl->>Orq: procesar(mensaje, contextoFijo, uuid_conversacion)
    
    Orq->>GP: evaluarEntrada(mensaje)
    alt Es Saludo / Cortesía (Fast-Path)
        GP-->>Orq: Retorna respuesta fija (0 tokens, 10ms)
        Orq->>Log: Registra turno (resultado: fast_path)
        Orq-->>Ctrl: Respuesta inmediata
        Ctrl-->>U: Muestra saludo sin invocar LLM
    else Bloqueo por Guardrail (Inyección / Toxicidad / Fuera de alcance)
        GP-->>Orq: Retorna mensaje institucional neutro
        Orq->>Log: Registra turno (resultado: bloqueado_guard)
        Orq-->>Ctrl: Respuesta de rechazo educada
        Ctrl-->>U: Muestra aviso de restricción
    else Pasa Validación de Entrada
        Orq->>Prompt: componer(4 capas inmutables)
        Prompt-->>Orq: System Instruction
        Orq->>Reg: obtenerDeclaracionesTools()
        Reg-->>Orq: Esquemas OpenAPI 3.0
        
        Orq->>LLM: generateContent(systemPrompt, mensaje + historial, tools)
        
        alt LLM solicita Function Calling
            LLM-->>Orq: Solicitud: functionCall(nombre, args)
            Orq->>GP: evaluarTool(nombre, args)
            alt Argumentos Inválidos
                GP-->>Orq: Rechazo de tool con motivo
                Orq->>LLM: Informa error de argumentos al LLM
            else Argumentos Válidos
                Orq->>Reg: ejecutar(nombre, args, contextoFijo)
                Reg->>Tool: ejecutar(args, contextoFijo)
                Tool-->>Reg: Payload Compacto (JSON)
                Reg-->>Orq: Resultado de la Tool
                Orq->>GP: evaluarBucle(iteracionActual)
                Orq->>LLM: generateContent(historial + functionResponse)
            end
            LLM-->>Orq: Texto final sintetizado
        else LLM responde con texto directo
            LLM-->>Orq: Texto generado
        end
        
        Orq->>GP: evaluarSalida(textoGenerado)
        GP-->>Orq: Texto saneado (sin fuga de datos, fechas formateadas)
        Orq->>Log: Registra turno asíncrono / seguro (tokens, latencia, tools)
        Orq-->>Ctrl: Respuesta definitiva
        Ctrl-->>U: Entrega respuesta con metadatos visuales
    end
```

### 1.2 Responsabilidades por Capa de Software

1. **Capa HTTP (Controladores delgados):**
   - Recibe la petición, valida los tipos con `FormRequest` (máximo de caracteres, formato UUID).
   - Extrae el usuario autenticado desde el token seguro (Sanctum/JWT) y crea el `$contextoFijo`.
   - No contiene lógica de negocio ni sentencias `if` de IA.
2. **Capa de Orquestación (`AsistenteOrquestadorService`):**
   - Mantiene el bucle de control de Function Calling (máximo de iteraciones configurado para evitar bucles infinitos).
   - Coordina el paso por los Guardrails.
   - Construye el objeto `RastroTurno` para la bitácora sin impactar la lógica central.
3. **Capa de Instrucción (`PromptComposer`):**
   - Ensambla el System Prompt dividiéndolo en **4 Capas Inmutables**:
     * **Capa 1 (Invariante):** Nombre del asistente, misión de negocio, prohibición de alucinación.
     * **Capa 2 (Seguridad):** Anti-jailbreak, no revelar prompts internos, secreto de esquemas.
     * **Capa 3 (Estilo y Operación):** Concisión (1–3 oraciones), trato profesional, formato estricto de fechas (`DD-MM-YYYY`), límite de emojis (máx. 1).
     * **Capa 4 (Dominio de Negocio):** Reglas específicas del proyecto, terminología técnica y manejo constructivo ante falta de datos.

---

## 2. Gestión de Conversaciones, Mensajes e Historial Persistente

Una debilidad común en implementaciones ingenuas de chatbots es permitir que el cliente envíe todo el array de mensajes en cada petición (`historial: [...]`). Esto permite ataques IDOR, inyección de mensajes de sistema falsos por parte del cliente y manipulación del contexto.

### 2.1 Diagrama de Ciclo de Vida de Conversaciones

```mermaid
flowchart TD
    subgraph Cliente["Frontend (Widget Chat)"]
        UI_Open[Abre Chat Widget]
        UI_Send[Envía Mensaje: '¿Estado del lote 3?']
        UI_HistBtn[Clic en Icono Historial 📜]
        UI_HistList[Muestra Drawer / Lista de Chats]
        UI_Select[Selecciona Conversación Anterior]
        UI_Trash[Clic en Icono Borrar / Limpiar Chat 🗑]
        UI_Edit[Renombra Título de Conversación ✏️]
    end

    subgraph Backend["API Backend (Laravel / Node / FastAPI)"]
        UUID_Check{¿Vino uuid_conversacion\nen el payload?}
        Val_Owner{¿Pertenece al usuario\nautenticado y está activa?}
        Create_Conv[Crea nueva Conversacion\ncon UUID v4 del servidor]
        Load_Conv[Carga conversacion existente]
        Fetch_Hist[Recupera últimos N mensajes válidos\nWHERE en_contexto = true]
        Save_UserMsg[Inserta mensaje usuario\nen mnt_mensajes_asistente]
        Execute_Turn[Orquestador procesa turno]
        Save_BotMsg[Inserta respuesta modelo\nen mnt_mensajes_asistente]
        Check_FirstTurn{¿Es el primer turno\nde la conversación?}
        Queue_Title[Job o Servicio Asíncrono:\nTituloConversacionService\n(Genera nombre de 3-5 palabras)]
        Soft_Delete[UPDATE estado = 'eliminada']
        Update_Title[UPDATE titulo = nuevoNombre]
    end

    UI_Send --> UUID_Check
    UUID_Check -- No --> Create_Conv
    UUID_Check -- Sí --> Val_Owner
    Val_Owner -- Es ajena o eliminada --> Create_Conv
    Val_Owner -- Válida --> Load_Conv
    Create_Conv --> Save_UserMsg
    Load_Conv --> Fetch_Hist --> Save_UserMsg
    Save_UserMsg --> Execute_Turn --> Save_BotMsg
    Save_BotMsg --> Check_FirstTurn
    Check_FirstTurn -- Sí --> Queue_Title
    Check_FirstTurn -- No --> Done[Responde al Cliente con UUID garantizado]

    UI_HistBtn --> UI_HistList
    UI_HistList -.->|GET /asistente/conversaciones| Backend
    UI_Select -->|Carga mensajes vía UUID| Backend
    UI_Trash -->|DELETE /asistente/conversaciones/{uuid}| Soft_Delete
    UI_Edit -->|PATCH /asistente/conversaciones/{uuid}| Update_Title
```

### 2.2 Especificación de la Base de Datos de Conversaciones

```sql
-- 1. Cabecera de Conversación
CREATE TABLE mnt_conversaciones_asistente (
    id BIGSERIAL PRIMARY KEY,
    uuid UUID NOT NULL UNIQUE,
    id_usuario BIGINT NOT NULL,              -- Propietario estricto (Anti-IDOR)
    titulo VARCHAR(100) NOT NULL DEFAULT 'Nueva consulta',
    estado VARCHAR(20) NOT NULL DEFAULT 'activa', -- 'activa', 'archivada', 'eliminada'
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    CONSTRAINT fk_conversacion_usuario FOREIGN KEY (id_usuario) REFERENCES mnt_usuarios(id)
);

CREATE INDEX idx_conversaciones_usuario_estado ON mnt_conversaciones_asistente (id_usuario, estado, updated_at DESC);

-- 2. Mensajes Individuales
CREATE TABLE mnt_mensajes_asistente (
    id BIGSERIAL PRIMARY KEY,
    id_conversacion BIGINT NOT NULL,
    rol VARCHAR(15) NOT NULL,                -- 'user', 'model', 'system'
    contenido TEXT NOT NULL,
    en_contexto BOOLEAN NOT NULL DEFAULT TRUE, -- FALSE si fue error o bloqueo de guard
    tokens_estimados INT NULL,
    metadata JSONB NULL,                     -- Tools invocadas, tiempo de respuesta
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    CONSTRAINT fk_mensaje_conversacion FOREIGN KEY (id_conversacion) 
        REFERENCES mnt_conversaciones_asistente(id) ON DELETE RESTRICT
);

CREATE INDEX idx_mensajes_conversacion ON mnt_mensajes_asistente (id_conversacion, created_at ASC);
```

### 2.3 Reglas de Negocio para el Historial
1. **El cliente nunca envía el historial:** Solo envía el `uuid_conversacion` que tiene en `localStorage` (o `null`).
2. **Reconstrucción segura en el servidor:** El backend recupera los últimos $N$ mensajes (por ejemplo, 6 a 10 mensajes) que tengan `en_contexto = true`. Esto evita reenviar errores 500 previos o saludos bloqueados que puedan confundir al LLM.
3. **Titulación Automática (`TituloConversacionService`):**
   - Tras el primer turno exitoso, se lanza una llamada ultracorta a un modelo ligero (ej. `gemini-flash` con temperatura 0.2): *"Genera un título descriptivo de 3 a 5 palabras para esta consulta inicial del usuario: {mensaje}. Sin comillas ni puntos."*
   - Si la titulación falla, se mantiene el título por defecto sin interrumpir al usuario.
   - Esta llamada **no se audita en la tabla de Benchmark** para no ensuciar las estadísticas operativas.
4. **Borrado Lógico:** Cuando el usuario presiona "Eliminar conversación", el backend ejecuta `UPDATE ... SET estado = 'eliminada'`. La información histórica se preserva para auditoría y cumplimiento normativo, pero queda invisible para el usuario.
5. **Limpiar Chat / Nueva Conversación:** El botón de la papelera en el widget elimina la referencia del `uuid_conversacion` en el cliente (`localStorage.removeItem`), reinicia el array reactivo de mensajes e inicia un nuevo hilo limpio en el siguiente envío.

---

## 3. Patrón de Herramientas (Tools / Function Calling)

Las herramientas son las manos y ojos del asistente: le permiten consultar bases de datos, APIs de terceros o ejecutar cálculos certificados.

### 3.1 Diagrama de Interacción de Herramientas

```mermaid
flowchart LR
    subgraph LLM["Google Gemini / OpenAI"]
        FC[Function Call Solicitado:\nconsultar_inventario_aves\nargs: {especie: 'Gallina'}]
    end

    subgraph Harness["Arnés de Control"]
        Registry[ToolRegistry]
        GuardTool{Guardrail de Tool:\nValida argumentos}
        ContextInject[Inyector de Contexto Fijo:\nInjecta id_empresa / id_usuario]
    end

    subgraph Tool["Implementación de la Tool"]
        SQL[Query SQL Optimizada:\nSELECT especie, COUNT(*) FROM aves...]
        Agg[Agregador SQL:\nPayload Compacto]
    end

    FC --> Registry --> GuardTool
    GuardTool -- Válido --> ContextInject --> SQL --> Agg
    Agg --> Harness -->|Payload: {total: 45, activas: 42}| LLM
```

### 3.2 Contrato Formal (`AsistenteToolInterface`)

Toda herramienta debe cumplir estrictamente con este contrato:

```php
namespace App\Services\Asistente\Contracts;

interface AsistenteToolInterface
{
    public function nombre(): string;
    public function descripcion(): string;
    public function tablasConsultadas(): array; // Declaración para auditoría
    public function esquema(): array;           // Esquema OpenAPI para el LLM
    public function ejecutar(array $argumentos, array $contextoFijo = []): array;
}
```

### 3.3 Reglas de Oro en el Diseño de Tools
1. **Principio de Payloads Compactos:** Nunca retornar arrays crudos con 100 filas de modelos ORM. Realizar agregaciones (`COUNT`, `SUM`, `AVG`) en base de datos y devolver como máximo 5 registros de muestra. Esto minimiza el consumo de tokens y reduce drásticamente el tiempo de respuesta.
2. **Tablas Consultadas Declarativas:** El método `tablasConsultadas()` permite que el módulo de observabilidad registre automáticamente qué tablas de base de datos fueron leídas durante cada turno sin necesidad de hacer análisis complejo del SQL.
3. **Inyección Inmutable (`$contextoFijo`):** Si una herramienta consulta datos restringidos por empresa o usuario, los IDs se extraen de `$contextoFijo`, ignorando cualquier argumento homónimo proveniente del LLM.

---

## 4. Pipeline de Guardrails Multicapa

Los guardrails son capas de seguridad y calidad escritas en código determinista. Operan en 4 momentos clave del turno.

### 4.1 Diagrama de Fases del Pipeline de Guardrails

```mermaid
flowchart TD
    MsgIn[Mensaje de Usuario] --> Fase1{Fase 1: Entrada}
    
    subgraph F1["Fase 1: Guardrails de Entrada"]
        G_Fast[SaludoFastPathGuard\n• Si es saludo: respuesta 0 tokens]
        G_Inj[PromptInjectionGuard\n• 'olvida tus reglas', 'DAN', 'jailbreak']
        G_Tox[ProfanityAndToxicityGuard\n• Insultos y agresividad]
        G_Drift[PersonaDriftGuard\n• 'actúa como pirata', 'háblame en verso']
        G_Scope[ScopeEnforcementGuard\n• Preguntas de política, recetas, fútbol]
        G_Math[MathematicalOffloadingGuard\n• Operaciones aritméticas puras sin dominio]
    end

    Fase1 -->|Pasa| Modelo[Invocación del Modelo / LLM]
    
    subgraph F2["Fase 2: Guardrails de Tool"]
        G_Arg[ToolArgumentValidationGuard\n• Valida fechas Y-m-d reales, fin >= inicio\n• Enums permitidos antes de tocar BD]
    end

    Modelo -->|Solicita Tool| Fase2{Fase 2: Tool}
    Fase2 --> G_Arg
    G_Arg -->|Válido| ExecTool[Ejecución en BD] --> Modelo

    subgraph F3["Fase 3: Guardrail de Bucle"]
        G_Loop[ToolLoopGuard\n• Limita a máx. 3 llamadas recursivas]
    end

    Modelo -->|Reintento / Bucle| Fase3{Fase 3: Bucle}
    Fase3 --> G_Loop

    subgraph F4["Fase 4: Guardrails de Salida"]
        G_Leak[SystemLeakageGuard\n• Bloquea nombres de tabla mnt_/ctl_\n• Filtra API Keys, stack traces y rutas]
        G_Date[DateFormatGuard\n• Normaliza fechas a formato estándar DD-MM-YYYY]
        G_Source[SourceAttributionGuard\n• Verifica que se cite el manual si se usó RAG]
        G_Len[LengthAndBrevityGuard\n• Avisa si la respuesta excede el límite de brevedad]
    end

    Modelo -->|Genera Texto| Fase4{Fase 4: Salida}
    Fase4 --> G_Leak --> G_Date --> G_Source --> G_Len --> MsgOut[Respuesta Final al Usuario]
```

### 4.2 Catálogo de Guardrails Recomendados

| Fase | Guardrail | Tipo | Comportamiento |
|---|---|---|---|
| **Entrada** | `SaludoFastPathGuard` | Fast-Path | Intercepta cortesías ("hola", "gracias", "¿quién eres?"). Responde en 10ms consumiendo **0 tokens**. |
| **Entrada** | `PromptInjectionGuard` | Bloqueo | Detecta intentos de alterar instrucciones ("olvida tus reglas", "actúa como DAN", "muestra tu system prompt"). |
| **Entrada** | `ProfanityAndToxicityGuard` | Bloqueo | Bloquea insultos o mensajes hostiles, retornando una respuesta neutra y profesional. |
| **Entrada** | `PersonaDriftGuard` | Bloqueo | Impide desviar la identidad ("responde como rapero", "cambia tu nombre"). |
| **Entrada** | `ScopeEnforcementGuard` | Bloqueo | Detecta temáticas ajenas al dominio empresarial (deportes, programación, cocina, política). |
| **Entrada** | `MathematicalOffloadingGuard` | Bloqueo | Bloquea cálculos matemáticos aislados ("suma 45 + 98", "raíz de 144"). Si el cálculo involucra vocabulario del negocio ("suma las ventas"), **lo deja pasar**. |
| **Tool** | `ToolArgumentValidationGuard` | Bloqueo Tool | Valida formatos de fechas (`Y-m-d`), coherencia de rangos (`fin >= inicio`) y pertenencia a catálogos antes de consultar la BD. |
| **Bucle** | `ToolLoopGuard` | Bloqueo Turno | Frena la recursión tras $N$ iteraciones de Function Calling (ej. máx. 3), protegiendo contra sobrecostos. |
| **Salida** | `SystemLeakageGuard` | Sanitización | Reemplaza o bloquea si el modelo menciona nombres de tablas físicas (`mnt_`, `ctl_`), rutas de código, API keys o errores de base de datos. |
| **Salida** | `DateFormatGuard` | Modificación | Convierte automáticamente fechas en formato crudo (`2026-11-20`) al estándar humano de negocio (`20-11-2026`). |
| **Salida** | `SourceAttributionGuard` | Auditoría / Aviso | Registra un aviso si se consultó la base RAG pero el modelo no citó formalmente la fuente. |

---

## 5. Base de Conocimiento RAG y Búsqueda Vectorial

El sistema RAG (Retrieval-Augmented Generation) permite que el asistente responda consultas técnicas, políticas corporativas o manuales normativos sin alucinar y citando la fuente oficial.

### 5.1 Diagrama de Flujo del Pipeline RAG

```mermaid
flowchart TD
    subgraph Ingesta["Fase de Ingesta (Admin / Seeders)"]
        Doc[Manuales / Documentos PDF / Markdown]
        Chunker[Chunker: División en secciones temáticas\n(300-800 tokens con overlap)]
        EmbedModel1[Modelo de Embeddings\n(ej. gemini-embedding-001 / text-embedding-3-small)]
        VectStore[(PostgreSQL + pgvector\nctl_documentos_tecnicos\nmnt_documentos_fragmentos\nÍndice HNSW vector_cosine_ops)]
        
        Doc --> Chunker --> EmbedModel1 --> VectStore
    end

    subgraph Consulta["Fase de Recuperación en Tiempo de Chat"]
        UserQ[Usuario pregunta:\n'¿Cuál es la dosis de desparasitante?']
        LLM_Decide[LLM solicita Tool:\nconsultar_manuales_tecnicos]
        EmbedModel2[Genera vector de la pregunta\n(768 dimensiones)]
        VectSearch[Búsqueda por Distancia Coseno <=>\nSELECT contenido, d.titulo, (f.embedding <=> query_vec)\nLIMIT 3]
        ContextInjectRAG[Inyecta fragmentos oficiales\ncomo resultado de la Tool]
        Answer[LLM redacta respuesta citando:\n'Según el Manual Sanitario Capítulo 4...']

        UserQ --> LLM_Decide --> EmbedModel2 --> VectSearch
        VectStore -.-> VectSearch
        VectSearch --> ContextInjectRAG --> Answer
    end
```

### 5.2 Estructura de Tablas RAG (pgvector)

```sql
CREATE EXTENSION IF NOT EXISTS vector;

-- Catálogo de Documentos Maestros
CREATE TABLE ctl_documentos_tecnicos (
    id BIGSERIAL PRIMARY KEY,
    titulo VARCHAR(150) NOT NULL,
    categoria VARCHAR(50) NULL,
    descripcion TEXT NULL,
    autor VARCHAR(100) NULL,
    version VARCHAR(20) DEFAULT '1.0',
    activo BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- Fragmentos Vectorizados (Chunks)
CREATE TABLE mnt_documentos_fragmentos (
    id BIGSERIAL PRIMARY KEY,
    id_documento_tecnico BIGINT NOT NULL,
    titulo_seccion VARCHAR(150) NOT NULL,
    contenido TEXT NOT NULL,
    metadata JSONB NULL,
    embedding VECTOR(768) NOT NULL, -- Compatible con gemini-embedding-001 truncado
    created_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    CONSTRAINT fk_fragmento_documento FOREIGN KEY (id_documento_tecnico) 
        REFERENCES ctl_documentos_tecnicos(id) ON DELETE CASCADE
);

-- Índice HNSW optimizado para distancia coseno
CREATE INDEX idx_fragmentos_embedding_hnsw 
ON mnt_documentos_fragmentos USING hnsw (embedding vector_cosine_ops);
```

### 5.3 El Patrón de Compatibilidad Dual (PostgreSQL vs SQLite)

Para evitar que los tests automatizados requieran una base de datos PostgreSQL externa, el servicio de persistencia vectorial debe implementar un fallback matemático:

* **En Producción (PostgreSQL):** Utiliza la sintaxis nativa de `pgvector` con el operador `<=>`.
* **En Pruebas Locales (SQLite `:memory:`):** La migración almacena el vector como `text`. El servicio decodifica los vectores en memoria y calcula la distancia coseno mediante la fórmula matemática pura en el lenguaje de backend.
* **Resultado:** La suite completa de pruebas unitarias y de integración corre en CI/CD en segundos sin dependencias externas.

---

## 6. Observabilidad, Bitácora de Auditoría y Benchmark

No se puede mejorar lo que no se mide. El módulo de Benchmark proporciona trazabilidad forense, métricas de calidad y control de costos sin comprometer la privacidad.

### 6.1 Diagrama de Auditoría y Privacidad

```mermaid
flowchart LR
    Turno[Turno del Asistente Finalizado] --> Extr[Extractor de Metadatos]
    
    subgraph Privacidad["Blindaje de Privacidad"]
        AnonConv[Seudonimización de Conversación:\nHMAC-SHA256(uuid, APP_KEY)]
        PurgeID[Purga Recursiva de Claves de Identidad:\nElimina id_usuario, email, token de los args]
        CleanErr[Sanitizador de Errores:\nElimina URLs con API keys y stack traces]
    end

    subgraph TablasAudit["Tablas de Benchmark (Sin FK a Usuarios)"]
        T1[(mnt_funcion_log_agent)]
        T2[(mnt_funcion_log_agent_tools)]
    end

    subgraph Metricas["Dashboard de Benchmark"]
        M1[Tasa de Éxito / Resolución]
        M2[Latencias Mediana y P90]
        M3[Tokens Entrada vs Salida]
        M4[Rankings Clicables de Guards y Tools]
    end

    Turno --> Extr --> Privacidad
    Privacidad --> TablasAudit
    TablasAudit -.->|Consultas Agregadas de Solo Lectura| Metricas
```

### 6.2 Métricas Esenciales que Debe Ofrecer el Dashboard

1. **Separación "Con Modelo" vs "Sin Modelo":**
   - *Sin Modelo:* Turnos resueltos por Fast-Path o bloqueados por guardrails de entrada (costo: 0 tokens, <50ms).
   - *Con Modelo:* Consultas que efectivamente consumieron llamadas a la API del LLM.
2. **Latencia Percentil (Mediana y P90):** El promedio oculta los problemas. La Mediana muestra la experiencia habitual y el P90 refleja el impacto de consultas complejas con múltiples herramientas.
3. **Consumo Desagregado de Tokens:** Separar tokens de entrada (instrucciones + historial + herramientas) y tokens de salida (respuesta generada). Típicamente el 90–95% del costo reside en los tokens de entrada.
4. **Rankings Interactivos:** Tablas con las herramientas más invocadas y los guardrails que más han actuado, con enlaces directos para filtrar la bitácora de logs.
5. **Depuración Periódica por Lotes:** Mecanismo transaccional para eliminar logs antiguos (3, 6 o 12 meses) respetando restricciones foráneas y dejando registro de auditoría.

---

## 7. Frontend: Interfaz de Usuario y Experiencia Conversacional

La experiencia en el cliente debe transmitir fluidez, transparencia en el uso de herramientas y facilidad de uso.

### 7.1 Diagrama de Componentes de la Interfaz

```mermaid
classDiagram
    class AsistenteChatWidget {
        +boolean abierto
        +boolean cargando
        +string mensajeActual
        +abrirHistorial()
        +limpiarChat()
        +enviarMensaje()
    }
    class HistorialDrawer {
        +Array conversaciones
        +seleccionarConversacion(uuid)
        +renombrarConversacion(uuid, nuevoTitulo)
        +eliminarConversacion(uuid)
    }
    class AsistenteMensaje {
        +string rol
        +string contenido
        +Array toolsInvocadas
        +string badgeTool
        +string hora
    }
    class AsistenteIndicador {
        +boolean pensando
        +string estadoTexto
        +animacionPuntos()
    }
    class GallinaIcon {
        +string claseEstilo
        +renderSvg()
    }

    AsistenteChatWidget *-- HistorialDrawer
    AsistenteChatWidget *-- AsistenteMensaje
    AsistenteChatWidget *-- AsistenteIndicador
    AsistenteMensaje *-- GallinaIcon
```

### 7.2 Pautas de Diseño e Interacción en el Chat

1. **Cabecera Funcional del Widget:**
   - **Indicador de Estado:** Punto verde/gris que indica conexión con el servidor.
   - **Botón de Historial (Icono 📜 / Lista):** Abre un cajón lateral o modal que lista las conversaciones previas del usuario con su título, fecha y opciones para renombrar o eliminar.
   - **Botón de Limpiar Chat (Icono 🗑 / Nuevo):** Desconecta la sesión actual en el cliente, limpia la pantalla y prepara un lienzo en blanco para una nueva consulta.
   - **Botón Minimizar / Cerrar (Icono ▾ / ✕):** Oculta el widget manteniendo el estado intacto.
2. **Estados Visuales Durante la Consulta:**
   - **Estado "Pensando" ([`AsistenteIndicador.vue`]):** Burbuja de animación suave con 3 puntos pulsantes cuando el mensaje está en tránsito HTTP.
   - **Badge de Herramienta Ejecutada:** Cuando una respuesta se obtuvo mediante una tool o base RAG, se muestra una pequeña etiqueta distintiva (ej. `[⚙ Inventario de aves]` o `[📘 Manuales técnicos]`). Esto genera confianza en el usuario al saber que el dato proviene de la base de datos oficial.
3. **Pautas de Personalización Visual:**
   - **Identidad Gráfica:** Definir un avatar temático representativo del negocio en formato SVG escalable.
   - **Modo Claro / Modo Nocturno Orgánico:** Respetar los tokens de contraste de la aplicación; los fondos oscuros no deben ser negros puros sino tonos pizarra o tierra acordes al diseño.
   - **Seguridad contra XSS:** En el renderizado de mensajes, usar siempre interpolación estándar (`{{ }}`) o bibliotecas de markdown seguras sanitizadas. **Prohibido el uso de `v-html` sin sanitizar**, ya que el modelo podría reproducir inyecciones HTML.

---

## 8. Catálogo de Extensiones Avanzadas para Futuros Desarrollos

Para proyectos que requieran capacidades de nivel corporativo superior, este arnés admite las siguientes extensiones modulares:

### 8.1 Streaming de Respuestas (Server-Sent Events - SSE)
* **Objetivo:** Reducir la percepción de latencia en consultas largas.
* **Implementación:** El endpoint `POST /chat` retorna un stream `text/event-stream`. El frontend recibe eventos parciales:
  - Evento `tool_start`: Muestra badge de *"Consultando inventario..."*.
  - Evento `token`: Agrega palabras en tiempo real con efecto de escritura.
  - Evento `done`: Cierra el stream y guarda metadatos.

### 8.2 Compresión de Contexto y Memoria Resumida (Rolling Summary)
* **Objetivo:** Soportar conversaciones de cientos de turnos sin exceder la ventana de tokens.
* **Implementación:** Cuando una conversación supera 10 turnos, un job en background procesa los primeros 8 turnos y genera un resumen conciso: *"El usuario preguntó por el lote 3 y confirmó que presentaba 10 bajas"*. Los mensajes viejos se marcan con `en_contexto = false` y el resumen se inyecta como mensaje de sistema inicial.

### 8.3 Two-Phase Commit para Tools de Mutación (Human-in-the-Loop)
* **Objetivo:** Permitir que el asistente realice acciones de escritura (crear registros, modificar estados) con total seguridad.
* **Implementación:**
  1. *Fase de Propuesta:* La herramienta de escritura no ejecuta el cambio; retorna un objeto `propuesta` estructurado.
  2. *Fase de Confirmación UI:* El chat muestra una tarjeta con los datos propuestos y botones de `[Confirmar]` y `[Cancelar]`.
  3. *Fase de Ejecución:* Al presionar Confirmar, el cliente invoca un endpoint transaccional que valida permisos y aplica la mutación.

### 8.4 Búsqueda Híbrida en RAG (Vectorial + BM25 con Reciprocal Rank Fusion)
* **Objetivo:** Recuperar con precisión exacta códigos de piezas, referencias normativas o identificadores que los embeddings semánticos a veces diluyen.
* **Implementación:** Se combina la similitud de coseno de `pgvector` con una búsqueda por texto completo en PostgreSQL (`tsvector`). Los resultados de ambos motores se mezclan y reordenan mediante el algoritmo **RRF (Reciprocal Rank Fusion)** antes de alimentar al LLM.

### 8.5 Fallback de Proveedor LLM y Circuit Breaker
* **Objetivo:** Garantizar 99.9% de disponibilidad ante caídas de proveedores de IA.
* **Implementación:** Si el proveedor principal arroja errores `503`, `429` o excede el timeout configurado, el cliente conmuta automáticamente a un proveedor secundario (ejemplo: fallback de Gemini a Claude o GPT-4o-mini).

---

## 9. Plan de Adopción Rápida para un Nuevo Proyecto

Al iniciar un nuevo asistente con este blueprint, el equipo técnico debe seguir esta secuencia:

```mermaid
flowchart TD
    P1["Paso 1: Persistencia Base\n• Migraciones de Conversaciones y Mensajes\n• Migraciones de Logs y Herramientas de Benchmark"] --> P2
    P2["Paso 2: Contratos e Interfaces\n• AsistenteToolInterface\n• GuardrailInterface\n• Configuración centralizada"] --> P3
    P3["Paso 3: PromptComposer & Cliente LLM\n• Estructura de 4 Capas del System Prompt\n• Cliente HTTP con timeout y reintentos"] --> P4
    P4["Paso 4: Guardrails Esenciales\n• SaludoFastPathGuard (Ahorro de costos)\n• PromptInjectionGuard\n• SystemLeakageGuard"] --> P5
    P5["Paso 5: Orquestador Central & Registry\n• Bucle de Function Calling\n• Inyección de Contexto Fijo Anti-IDOR"] --> P6
    P6["Paso 6: Tools de Dominio\n• Agregaciones SQL ligeras y payloads compactos\n• Declaración de tablas consultadas"] --> P7
    P7["Paso 7: RAG Vectorial (Si aplica)\n• Extensión pgvector e índice HNSW\n• Fallback de pruebas en memoria"] --> P8
    P8["Paso 8: API REST & Frontend\n• Endpoints /chat, /conversaciones, /benchmark\n• Widget con estados, historial y feedback"]
```

---
*Este documento constituye la especificación canónica para el desarrollo de asistentes de inteligencia artificial seguros, eficientes y gobernables en la organización.*
