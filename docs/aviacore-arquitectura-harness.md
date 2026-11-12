# Arquitectura del Asistente AviaCore: Harness, Function Calling y Tools

> **Proyecto:** Granja Avícola Inteligente (Sistema de Control Integral)  
> **Componente:** Asistente Virtual Conversacional (**AviaCore**)  
> **Ubicación:** `avicola-backend-api` & `avicola-frontend`  
> **Estándar:** Spec-Driven Development (SDD) & Clean Architecture  
> **Fecha de Elaboración:** 09 de Noviembre de 2026  

---

## 1. Visión General y Filosofía de Diseño

**AviaCore** es el asistente virtual de inteligencia artificial integrado en la plataforma de la Granja Avícola Inteligente. Su propósito es acompañar al personal operativo, zootecnistas y administradores en la consulta ágil de datos de campo, censos de población avícola, control de postura de huevos, suministro de alimentos, finanzas de la granja y, en etapas posteriores, recomendaciones zootécnicas especializadas extraídas de manuales técnicos.

```
┌────────────────────────────────────────────────────────────────────────┐
│                        INTERFAZ DE USUARIO (SPA)                       │
│  AviaCore Widget (Vue 3 + Pinia + Tailwind CSS + GallinaIcon)          │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │ POST /api/v1/asistente/chat
                                    ▼
┌────────────────────────────────────────────────────────────────────────┐
│                        BACKEND API (LARAVEL 11)                        │
│  AsistenteController ──► AsistenteOrquestadorService                   │
│                                │                                       │
│        GuardrailPipeline ◄─────┤ (entrada · tool · bucle · salida)     │
│                                │                                       │
│          ┌─────────────────────┴─────────────────────┐                 │
│          ▼                                           ▼                 │
│   PromptComposer (4 capas)                  AsistenteToolRegistry      │
│          │                                           │                 │
│          ▼                                           ▼                 │
│   GeminiApiClient                     6 Tools (5 operativas + RAG)     │
│   (gemini-3.1-flash-lite)             (Inventario, Huevos, Gastos, ...)│
│                                │                                       │
│        FuncionLogAgentService ◄┘ (auditoría del turno → Benchmark)     │
└───────────────────────────────────┬────────────────────────────────────┘
                                    │
                                    ▼
                 Base de Datos PostgreSQL 16 / SQLite Test
```

### Principios Rectores de Arquitectura

1. **Zero-Hallucination via Tool Grounding (Cero Alucinación):**  
   El modelo de lenguaje (LLM) **nunca** inventa cifras, conteos, ubicaciones o estados contables. Su única fuente de verdad sobre el estado actual de la granja son las herramientas autorizadas (*tools*). Si los datos no existen en la base de datos, el asistente lo comunica honestamente sin especular.
2. **Seguridad y Anti-IDOR (`contexto_fijo`):**  
   El LLM solo recibe esquemas de parámetros de negocio (fechas, especie, concepto). Parámetros sensibles como el identificador del usuario autenticado (`id_usuario`) son inyectados directamente por el backend de manera inmutable, impidiendo cualquier manipulación o intento de escalamiento horizontal (IDOR).
3. **Open-Closed Principle (OCP) y Desacoplamiento:**  
   El orquestador no contiene sentencias condicionales `switch/case` ni conoce las tablas del sistema. Cada herramienta es una clase aislada que implementa la interfaz `AsistenteToolInterface`. Agregar una nueva funcionalidad (por ejemplo, RAG en Etapa 8) no requiere modificar el controlador ni el orquestador principal.
4. **Modo Operate y UX Agropecuaria:**  
   Cumpliendo con `avicola-frontend/DESIGN.md`, las respuestas son concisas (1 a 3 oraciones), en trato de tú profesional, con formato de fechas estricto (`DD-MM-YYYY`) y respaldadas visualmente por la identidad corporativa y el imagotipo [`GallinaIcon.vue`].

### 1.1 Estructura de carpetas del harness

Todo el asistente vive en `app/Services/Asistente/` (backend) y en las carpetas `asistente/` y `benchmark/` del frontend. Cada carpeta tiene una sola responsabilidad:

```text
avicola-backend-api/
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   │   ├── AsistenteController.php            # POST /asistente/chat (delgado: valida, inyecta contexto, delega)
│   │   │   ├── AsistenteConversacionController.php # GET/PATCH/DELETE /asistente/conversaciones/* y POST .../titulo (historial)
│   │   │   ├── AsistenteBenchmarkController.php   # GET /asistente/benchmark/* (estadísticas, logs y detalle)
│   │   │   └── DepuracionLogsAgenteController.php # GET/DELETE /asistente/benchmark/depuracion (purga manual)
│   │   └── Requests/Asistente/
│   │       ├── ChatRequest.php                    # mensaje (1-1000) y uuid_conversacion (pista de qué conversación retomar)
│   │       ├── RenombrarConversacionRequest.php   # nombre de la conversación (1-60, una sola línea)
│   │       ├── EstadisticasBenchmarkRequest.php   # dias = 7 | 30
│   │       ├── FiltroLogsAgenteRequest.php        # filtros del listado de logs
│   │       └── DepuracionLogsAgenteRequest.php    # meses = 3 | 6 | 12 (lista cerrada)
│   ├── Models/
│   │   ├── FuncionLogAgent.php                    # turno auditado (mnt_funcion_log_agent)
│   │   ├── FuncionLogAgentTool.php                # tool ejecutada en un turno
│   │   ├── ConversacionAsistente.php              # conversación del usuario (mnt_conversaciones_asistente; nunca se borra)
│   │   ├── MensajeAsistente.php                   # mensaje de una conversación, con la bandera rol (user | model)
│   │   ├── CtlDocumentoTecnico.php                # catálogo de manuales (RAG)
│   │   └── MntDocumentoFragmento.php              # fragmentos con vector (RAG)
│   ├── Console/Commands/IngestarManualesCommand.php   # php artisan avicola:ingestar-manuales
│   ├── Providers/AppServiceProvider.php           # registro OCP: ASISTENTE_TOOLS y ASISTENTE_GUARDRAILS
│   └── Services/Asistente/
│       ├── AsistenteOrquestadorService.php        # CEREBRO: coordina guards, Gemini, tools y auditoría
│       ├── AsistenteToolRegistry.php              # catálogo y despachador de tools
│       ├── GeminiApiClient.php                    # cliente HTTP de Gemini (generateContent y embeddings)
│       ├── PromptComposer.php                     # System Instruction en 4 capas
│       ├── ModuloNavigationResolver.php           # sugerencias de módulos + sanitizado de nombres de tools
│       ├── SaludoDetectorService.php              # detección de saludos (lo usa SaludoFastPathGuard)
│       ├── Contracts/AsistenteToolInterface.php   # contrato de toda tool
│       ├── Tools/                                 # una clase por herramienta (6)
│       ├── Rag/                                   # VectorStoreService e IngestaManualesService (pgvector)
│       ├── Guardrails/                            # protecciones en código, por fase
│       │   ├── Contracts/GuardrailInterface.php   #   contrato de todo guard
│       │   ├── GuardrailBase.php                  #   utilidades comunes (normalizar texto, config)
│       │   ├── GuardrailPipeline.php              #   ejecuta los guards por fase
│       │   ├── ContextoGuardrail.php · ResultadoGuardrail.php · ResultadoPipeline.php
│       │   ├── Entrada/                           #   7 guards, antes de llamar al modelo
│       │   ├── Tool/                              #   ToolArgumentValidationGuard
│       │   ├── Bucle/                             #   ToolLoopGuard
│       │   └── Salida/                            #   4 guards, sobre la respuesta del modelo
│       ├── Conversacion/                          # conversaciones persistentes (por usuario)
│       │   ├── ConversacionAsistenteService.php   #   buscar/crear, contexto, guardar turnos, listar, renombrar, "eliminar" (cambia el estado)
│       │   └── TituloConversacionService.php      #   nombre automático con una llamada corta a Gemini
│       └── Benchmark/                             # auditoría
│           ├── RastroTurno.php                    #   acumula lo ocurrido en un turno (sin tocar la BD)
│           ├── FuncionLogAgentService.php         #   persiste el turno; nunca lanza
│           ├── BenchmarkConsultaService.php       #   estadísticas, listado y detalle
│           └── DepuracionLogsAgenteService.php    #   vista previa y borrado por lotes (3, 6, 12 meses)
├── config/
│   ├── asistente.php                              # contexto (6 mensajes), listado y nombre de las conversaciones
│   ├── gemini.php                                 # api_key, modelo, timeout, embeddings
│   └── guardrails.php                             # mensajes, vocabulario de la granja y patrones
├── database/{migrations,seeders}/                 # tablas del log; BenchmarkDemoSeeder y DocumentosTecnicosSeeder
└── tests/Feature/                                 # AsistenteTest, RagTest, Guardrails*Test, Benchmark*Test

avicola-frontend/src/
├── components/asistente/    # AsistenteChatWidget, AsistenteMensaje, AsistenteIndicador
├── components/benchmark/    # SerieDiariaCard, RankingCard, LogDetalleModal
├── stores/                  # asistente.js (chat, conversación persistente e historial) y benchmark.js
├── services/                # asistente.js y benchmark.js (clientes HTTP)
├── utils/benchmark.js       # etiquetas de resultado, acción y fase de los guards
└── views/BenchmarkView.vue  # pantalla /benchmark
```

**Responsabilidades por capa** (de afuera hacia adentro):

| Capa | Piezas | Responsabilidad | No hace |
|---|---|---|---|
| **HTTP** | Controladores y `FormRequest` | Autenticación (Sanctum), validación de entrada, contexto fijo, respuesta JSON estándar | Lógica de negocio ni de IA |
| **Orquestación** | `AsistenteOrquestadorService` | Coordina el turno de principio a fin y arma el rastro de auditoría | Conocer tablas ni reglas de cada guard o tool |
| **Protección** | `Guardrails/` | Bloquea, corrige o avisa en cuatro momentos del turno | Llamar al modelo ni consultar la base |
| **Inteligencia** | `PromptComposer`, `GeminiApiClient` | Compone las instrucciones y habla con Gemini | Decidir qué es seguro |
| **Datos** | `Tools/`, `Rag/`, `AsistenteToolRegistry` | Consultas acotadas y compactas a PostgreSQL y pgvector | Redactar respuestas |
| **Auditoría** | `Benchmark/` | Guarda cada turno (sin datos del usuario) y lo expone en solo lectura | Afectar al chat si falla |

**Puntos de extensión (OCP):** agregar una herramienta es una clase en `Tools/` más una línea en `ASISTENTE_TOOLS`; agregar un guard es una clase en `Guardrails/<Fase>/` más una línea en `ASISTENTE_GUARDRAILS`. El orquestador no se modifica en ninguno de los dos casos. El detalle de guardrails y auditoría está en [`aviacore-benchmark-guardrails.md`](aviacore-benchmark-guardrails.md) y el del RAG en [`aviacore-rag-embeddings.md`](aviacore-rag-embeddings.md).

---

## 2. Explicación General y Funcionamiento en Lenguaje Natural

Para comprender cómo opera AviaCore de manera intuitiva sin necesidad de sumergirse de inmediato en código o esquemas técnicos, imaginemos el sistema como un **restaurante de alta cocina bien coordinado**:

* El **Usuario (Operador / Administrador)** es el **comensal** que realiza una consulta o pide un platillo.
* El **Frontend (Vue 3 + Pinia)** es la **mesa y la carta**, donde el comensal visualiza el menú e interactúa con comodidad.
* El **Controlador (`AsistenteController`)** es el **recepcionista en la puerta**, quien verifica que el cliente tenga reservación y esté autenticado con su carnet.
* El **Orquestador (`AsistenteOrquestadorService`)** es el **jefe de cocina (maître)**, quien coordina a todo el equipo y hace respetar estrictamente las normas de la casa.
* **Google Gemini (LLM `gemini-3.1-flash-lite`)** es el **chef consultor inteligente**, quien domina el lenguaje humano, comprende la intención del comensal y decide qué ingredientes se requieren para satisfacer la solicitud.
* Las **Herramientas (Tools de Dominio)** son los **cocineros especializados de cada estación**, los únicos autorizados para acceder a la despensa y preparar las porciones requeridas.
* La **Base de Datos (PostgreSQL 16)** es la **bodega o despensa central**, donde se custodian los datos reales y auditados de las aves, la postura y las finanzas.

### El Viaje de un Mensaje en 7 Pasos Simples

```
[1. Usuario escribe en Chat]
           │
           ▼
[2. Frontend envía a la API] ──► [3. Controlador valida sesión e inyecta usuario]
                                                   │
                                                   ▼
                                     [4. Orquestador prepara reglas]
                                                   │
                                                   ▼
                                      [5. Gemini pide datos reales]
                                                   │ (Function Calling)
                                                   ▼
                                  [6. Tool consulta PostgreSQL]
                                                   │ (Payload compacto)
                                                   ▼
                                  [7. Gemini redacta la respuesta]
                                                   │
                                                   ▼
[Operador lee respuesta en pantalla con GallinaIcon y Badge de Tool]
```

1. **Paso 1: El usuario escribe su consulta**  
   El operador abre la ventanita de AviaCore en el navegador (`http://localhost:5173`) y escribe en lenguaje natural:  
   *`"¿Cuántas aves tenemos activas en la granja?"`* y presiona Enviar.
2. **Paso 2: El frontend prepara la experiencia**  
   Al instante, el componente [`AsistenteChatWidget.vue`] agrega el mensaje en una burbuja color salvia y activa la animación de tres puntos pulsantes ([`AsistenteIndicador.vue`]), informando visualmente que AviaCore está trabajando en la respuesta.
3. **Paso 3: El controlador recibe y protege el acceso**  
   La petición viaja vía HTTP segura (`POST /api/v1/asistente/chat`). [`ChatRequest.php`] verifica que el mensaje sea válido y [`AsistenteController.php`] extrae el identificador real del usuario autenticado (`$contextoFijo`), impidiendo que nadie pueda falsificar su identidad.
4. **Paso 4: El orquestador ensambla el contexto**  
   El orquestador toma el mensaje y prepara el paquete completo para la IA:
   - Las **reglas de la granja**: las 4 capas de [`PromptComposer.php`] (identidad AviaCore, reglas anti-hackeo, estilo conciso de 1-3 frases y normas zootécnicas).
   - El **menú de herramientas**: la lista de tools registradas en [`AsistenteToolRegistry.php`] (inventario, huevos, gastos, ubicación y raciones).
5. **Paso 5: La IA decide qué información necesita (*Function Calling*)**  
   Gemini lee la pregunta. Al estar estrictamente instruido para no especular ni inventar cifras, no emite texto inmediatamente, sino que responde al servidor:  
   👉 *"Para responder con exactitud, requiero que ejecutes la herramienta `consultar_inventario_aves`"*.
6. **Paso 6: La herramienta consulta los datos reales en PostgreSQL**  
   El orquestador recibe la instrucción y delega la ejecución en [`ConsultarInventarioAvesTool.php`]. La herramienta realiza una consulta SQL optimizada a la base de datos y genera una respuesta compacta:  
   `{ "total_aves": 15, "por_especie": { "Gallina": 10, "Chompipe": 5 }, "por_sexo": { "Hembra": 10, "Macho": 5 } }`.
7. **Paso 7: Gemini sintetiza la respuesta humana y el frontend la presenta**  
   El orquestador le reenvía las cifras verídicas a Gemini, quien redacta la respuesta final en lenguaje amigable:  
   > *"Actualmente la granja cuenta con 15 aves activas: 10 gallinas criollas y 5 chompipes (10 hembras y 5 machos). 🐔"*  
   
   El frontend recibe este texto, apaga los puntos pulsantes y dibuja la burbuja del asistente con el avatar corporativo [`GallinaIcon.vue`] y una etiqueta transparente que dice: `[⚙ Inventario de aves]`. Todo este ciclo ocurre en apenas **1.5 a 2.5 segundos**.

---

## 3. Flujo Técnico del Harness (Ciclo de Vida de un Mensaje y Secuencia)

A nivel de arquitectura de software, el siguiente diagrama de secuencia describe el flujo detallado de llamadas internas entre capas y servicios:

```mermaid
sequenceDiagram
    autonumber
    actor Operador as Operador / Usuario
    participant UI as Widget AviaCore (Vue 3)
    participant Ctrl as AsistenteController
    participant Orq as AsistenteOrquestadorService
    participant Reg as AsistenteToolRegistry
    participant Tool as Tool de Dominio (Ej. Inventario)
    participant Client as GeminiApiClient
    participant LLM as Google Gemini (3.1-flash-lite)

    Operador->>UI: Escribe "¿Cuántas gallinas y patos tenemos activos?"
    UI->>Ctrl: POST /api/v1/asistente/chat { mensaje, uuid_conversacion }
    Ctrl->>Ctrl: Resuelve la conversación del usuario y arma el contexto (últimos mensajes exitosos)
    Ctrl->>Orq: procesar(mensaje, contexto, contextoFijo)
    
    Orq->>Orq: Compone System Instruction (PromptComposer::componer)
    Orq->>Reg: obtenerDeclaracionesTools()
    Reg-->>Orq: Array de esquemas OpenAPI para Gemini
    
    Orq->>Client: generarRespuesta(systemInstruction, contents, tools)
    Client->>LLM: POST /v1beta/models/gemini-3.1-flash-lite:generateContent
    
    LLM-->>Client: 200 OK con functionCall: { name: 'consultar_inventario_aves', args: {} }
    Client-->>Orq: Respuesta con llamadas a herramientas pendientes
    
    Note over Orq,Tool: Ciclo de Function Calling (Iteración 1)
    Orq->>Reg: ejecutar('consultar_inventario_aves', args, contextoFijo)
    Reg->>Tool: ejecutar(args, contextoFijo)
    Tool->>Tool: Consulta Ave (mnt_aves) con estado = 'activa'
    Tool-->>Reg: Payload Compacto: { total_aves: 15, por_especie: {...} }
    Reg-->>Orq: Resultado estructurado de la herramienta
    
    Orq->>Orq: Añade respuesta de la tool al historial (role: 'function')
    Orq->>Client: Re-envía conversación con el resultado de la función
    Client->>LLM: POST generateContent (con historial + resultado)
    
    LLM-->>Client: 200 OK con texto final sintetizado
    Client-->>Orq: "Actualmente la granja cuenta con 15 aves activas: 8 gallinas, 4 chompipes y 3 patos."
    
    Orq-->>Ctrl: Array { respuesta, tools_invocadas: ['consultar_inventario_aves'] }
    Ctrl-->>UI: 200 OK JSON estándar
    UI->>Operador: Muestra respuesta en burbuja con GallinaIcon y badge de herramienta
```

### Detalle de las Etapas del Pipeline

1. **Recepción y Validación (`ChatRequest`):**  
   El endpoint valida que el mensaje no esté vacío (máximo 1,000 caracteres). El historial **ya no lo envía el navegador**: el servidor lo arma desde la conversación guardada del usuario (ver README del backend, 5.10) y descarta cualquier `historial` que llegue en la petición.
2. **Inyección de Contexto Fijo:**  
   El controlador extrae de la petición autenticada vía Laravel Sanctum el `id_usuario` y lo empaqueta en un array inmutable:
   ```php
   $contextoFijo = [
       'id_usuario' => $request->user()->id_usuario,
       'rol' => $request->user()->id_rol,
   ];
   ```
3. **Orquestación con Gemini Function Calling:**  
   `AsistenteOrquestadorService` coordina la llamada al modelo. Si el modelo devuelve una o más solicitudes `functionCall`, el orquestador:
   - Resuelve la herramienta en `AsistenteToolRegistry`.
   - Inyecta el `$contextoFijo` a la ejecución.
   - Serializa la respuesta como `functionResponse`.
   - Reinvoca al modelo de lenguaje para que redacte la respuesta final en lenguaje natural comprensible para el operador.
4. **Protección contra Bucles Infinitos:**  
   El orquestador limita las iteraciones a 3 (hoy `ToolLoopGuard::LIMITE`; antes la constante `MAX_ITERACIONES`). Si por algún fallo el modelo entra en un bucle solicitando herramientas de forma descontrolada, el orquestador frena la recursión y entrega un mensaje controlado para evitar sobrecostos o bloqueos.
5. **Atajo de Saludos (0 tokens):**  
   Antes de llamar al modelo, `SaludoDetectorService` detecta saludos, cortesías, despedidas y preguntas de identidad puras ("hola", "gracias", "¿quién eres?") y responde con un texto predeterminado, sin consumir tokens ni latencia del proveedor. Hoy lo ejecuta `SaludoFastPathGuard`, que envuelve el mismo servicio.
6. **Saneamiento de la Respuesta:**  
   Antes de entregar el texto, `ModuloNavigationResolver::sanitizarRespuesta` reemplaza cualquier mención inadvertida a nombres internos de herramientas (`consultar_gastos_periodo`, etc.) por el módulo operativo correspondiente. Hoy lo reutiliza `SystemLeakageGuard`, que además bloquea la fuga de nombres de tabla, rutas, trazas y API keys.

> Estas validaciones, junto con las nuevas, están organizadas por fase en [`aviacore-benchmark-guardrails.md`](aviacore-benchmark-guardrails.md) (sección 2.7 de la parte 2 indica cuáles ya existían y dónde quedó cada una).

---

## 4. Composición del System Prompt en 4 Capas Inmutables

El comportamiento de AviaCore se rige mediante un *System Instruction* dinámico estructurado por la clase [`PromptComposer.php`]:

```
┌────────────────────────────────────────────────────────┐
│  CAPA 1: INVARIANTE                                    │
│  - Identidad: AviaCore.                                │
│  - Misión de asistencia operativa y zootécnica.        │
│  - Prohibición estricta de alucinación de cifras.      │
├────────────────────────────────────────────────────────┤
│  CAPA 2: SEGURIDAD                                     │
│  - Anti-Jailbreak y Anti-Prompt-Injection.             │
│  - Protección de esquemas internos y credenciales.     │
│  - Neutralidad y trato ante manipulación.              │
├────────────────────────────────────────────────────────┤
│  CAPA 3: ESTILO (MODO OPERATE)                         │
│  - Brevedad: 1 a 3 oraciones concisas o viñetas.       │
│  - Tono: cercano y profesional agropecuario (tú).      │
│  - Fechas: DD-MM-YYYY obligatorio (ej. 09-11-2026).    │
│  - Máximo 1 emoji contextual (ej. 🥚, 🐔).             │
├────────────────────────────────────────────────────────┤
│  CAPA 4: DOMINIO AVÍCOLA                               │
│  - Casitas e instalaciones vs Divisiones y corrales.   │
│  - Regla biológica: separación estricta de especies.   │
│  - Huevos aprovechables = (recolectados - rotos).      │
│  - Cero resultados: respuesta constructiva y clara.   │
└────────────────────────────────────────────────────────┘
```

Esta arquitectura por capas permite modificar las reglas de estilo o agregar reglas de dominio zootécnico (como guías sanitarias) sin alterar las directrices de seguridad ni la identidad del asistente.

---

## 5. Patrón de Herramientas (Tools / Function Calling)

### 5.1 Contrato Formal (`AsistenteToolInterface`)

Todas las herramientas implementan la siguiente interfaz PHP:

```php
namespace App\Services\Asistente\Contracts;

interface AsistenteToolInterface
{
    public function nombre(): string;
    public function descripcion(): string;
    public function tablasConsultadas(): array;   // tablas que lee la tool (auditoría del Benchmark)
    public function esquema(): array;
    public function ejecutar(array $argumentos, array $contextoFijo = []): array;
}
```

`esquema()` genera el esquema JSON compliant con **OpenAPI 3.0** que Gemini requiere para decidir cuándo y cómo llamar a la herramienta. `AsistenteToolRegistry` lo combina con `nombre()` y `descripcion()` en `obtenerDeclaracionesTools()`. `tablasConsultadas()` es un dato declarativo (no inferido) que el orquestador guarda en la auditoría de cada turno.

### 5.2 Registro OCP (`AsistenteToolRegistry`)

El registro central no conoce las implementaciones directas; las herramientas se registran e inyectan en [`AppServiceProvider.php`]:

```php
private const ASISTENTE_TOOLS = [
    ConsultarInventarioAvesTool::class,
    // ... una línea por herramienta
];

$this->app->tag(self::ASISTENTE_TOOLS, 'asistente.tools');

$this->app->when(AsistenteToolRegistry::class)
    ->needs('$herramientas')
    ->giveTagged('asistente.tools');
```

Los guardrails siguen exactamente el mismo patrón con `ASISTENTE_GUARDRAILS` y el tag `asistente.guardrails`, inyectados en `GuardrailPipeline`.

### 5.3 Principio de Payloads Compactos (Optimización de Context Window)

Las herramientas de AviaCore nunca retornan colecciones masivas de modelos Eloquent con cientos de columnas innecesarias. Retornan **agregaciones SQL ligeras** preparadas para el consumo óptimo del LLM:

- **Mínimo consumo de tokens:** Respuestas de 50 a 150 tokens en lugar de miles de tokens de volcado crudo.
- **Menor latencia:** Reducción drástica en el tiempo de procesamiento de Gemini.
- **Ahorro de costos:** Menor volumen de tokens de entrada/salida en el plan de API.

---

## 6. Catálogo de Herramientas Implementadas

Seis herramientas en `app/Services/Asistente/Tools/` (cinco operativas de la Etapa 7 y la de manuales técnicos de la Etapa 8). La columna de tablas es lo que declara cada una en `tablasConsultadas()` y es lo que aparece en el detalle de cada turno del Benchmark. Los argumentos son los del esquema real que recibe Gemini; todos son opcionales salvo los marcados como obligatorios.

| Herramienta | Tablas (`tablasConsultadas()`) | Propósito y argumentos | Salida compacta |
|---|---|---|---|
| `consultar_inventario_aves` | `mnt_aves`, `ctl_especie`, `ctl_sexo`, `mnt_divisiones`, `mnt_casitas` | Población e inventario de aves. `especie`, `sexo`, `id_division`, `estado` (`activa` por defecto, `salida` o `todos`). | `total_aves`, `por_especie`, `por_sexo` y hasta 5 `ejemplos` (código, nombre, especie, sexo, ubicación, peso). |
| `consultar_produccion_huevos` | `mnt_registros_huevos` | Postura de huevos en un periodo o corral. `dias` (por defecto 7), `fecha_inicio`, `fecha_fin` (`Y-m-d`), `id_division`. | Periodo (`desde`/`hasta` en `DD-MM-YYYY`), totales de recolectados, rotos y aprovechables, `porcentaje_rotura` y hasta 7 días de `resumen_diario_reciente`. |
| `consultar_gastos_periodo` | `mnt_gastos`, `ctl_categorias_gastos` | Gastos operativos y compras. `mes`, `anio`, `fecha_inicio`, `fecha_fin` (`Y-m-d`), `categoria` (coincidencia parcial, ej. Alimento, Medicina). | `total_gastado`, `por_categoria` y los 5 `ultimos_gastos` (fecha, monto, categoría, concepto, proveedor). |
| `consultar_ubicacion_ave` | `mnt_aves`, `ctl_especie`, `ctl_sexo`, `ctl_proposito`, `mnt_divisiones`, `mnt_casitas`, `mnt_movimientos_aves` | Localiza un ave. **`criterio` obligatorio**: código (ej. `AVE-001`) o nombre (ej. `Rubia`), coincidencia exacta. | `encontrado` y, si existe, el ave (código, nombre, especie, sexo, raza, estado, peso, casita y corral) y su `ultimo_movimiento`. |
| `consultar_raciones_alimento` | `mnt_registros_alimentacion`, `ctl_tipos_alimento`, `mnt_divisiones`, `mnt_casitas` | Alimento suministrado. `dias` (por defecto 7), `fecha_inicio`, `fecha_fin`, `id_division`, `tipo_alimento` (coincidencia parcial). | `total_kilos`, `total_costo`, `por_tipo_alimento` (kg) y los 5 `ultimos_suministros`. |
| `consultar_manuales_tecnicos` | `mnt_documentos_fragmentos`, `ctl_documentos_tecnicos` | Búsqueda semántica (RAG, pgvector) en los manuales zootécnicos. **`consulta` obligatoria**, `especie` opcional (Gallina, Chompipe, Pato). | Hasta 3 `fragmentos` (documento, sección, especie, contenido) o, si no hay cobertura, `orientacion_pedagogica` para derivar al veterinario. Detalle en [`aviacore-rag-embeddings.md`](aviacore-rag-embeddings.md). |

**Notas del comportamiento real**

- **Validación previa:** `ToolArgumentValidationGuard` revisa `fecha_inicio`/`fecha_fin` (y los alias `fecha_desde`/`fecha_hasta`) y `especie` **antes** de ejecutar la herramienta: fechas `Y-m-d` reales, fin no anterior al inicio y especie en Gallina, Chompipe o Pato. Si falla, la herramienta no se ejecuta y el modelo recibe el motivo.
- **Zona horaria:** "hoy" y los rangos se calculan con `config('avicola.timezone')` (`America/El_Salvador`).
- **Fechas de salida:** las herramientas devuelven las fechas en `DD-MM-YYYY`.
- **Tamaño de las muestras:** 5 registros recientes (7 en el resumen diario de huevos) para no saturar la ventana de tokens (ver 5.3).
- **Sin dato de usuario:** ninguna herramienta usa `id_usuario`; el contexto fijo se inyecta pero estas consultas son de la granja completa.

---

## 7. Frontend: Widget Conversacional y Tokens Visuales

El widget de AviaCore fue diseñado respetando rigurosamente los principios de accesibilidad, modularidad y paleta cromática definidos en `avicola-frontend/DESIGN.md`:

```
┌────────────────────────────────────────────────────────┐
│  AviaCore  ●                                  [🗑]  [v] │
│  Consultas operativas en lenguaje natural              │
├────────────────────────────────────────────────────────┤
│                                                        │
│  [🐔] AviaCore                                         │
│  Actualmente la granja cuenta con 15 aves activas:     │
│  • 10 Gallinas criollas                                │
│  • 5 Chompipes                                         │
│                                                        │
│  [ ⚙ Inventario de aves ]                              │
│                                                        │
├────────────────────────────────────────────────────────┤
│ [ Pregunta sobre aves, huevos, gastos...       ] [ ► ] │
│ Presiona Enter para enviar      AviaCore IA + F.Calling│
└────────────────────────────────────────────────────────┘
```

### Componentes Involucrados

1. [`AsistenteChatWidget.vue`]:
   - Ventana modal flotante anclada en la esquina inferior derecha (`z-40`).
   - Cabecera corporativa en color `bosque` (`#244837`) con el imagotipo [`GallinaIcon.vue`].
   - Indicador de estado operativo con animación de pulso verde (`salvia`).
   - Sugerencias rápidas de preguntas frecuentes en el estado vacío inicial.
   - Botón disparador circular con contador dinámico (badge) de mensajes pendientes.
2. [`AsistenteMensaje.vue`]:
   - Distinción visual clara entre mensajes del usuario (`salvia`) y respuestas del modelo (`bosque`).
   - Avatar corporativo `GallinaIcon.vue` en los mensajes de AviaCore.
   - Formateador ligero para negritas (`**`), listas con viñetas y saltos de línea.
   - **Badge de Herramienta Ejecutada:** Al invocarse una tool, muestra una etiqueta sutil (ej. *"Inventario de aves"*) para brindar total transparencia sobre el origen del dato.
3. [`AsistenteIndicador.vue`]:
   - Animación de tres puntos pulsantes en color `salvia` para indicar que el modelo está ejecutando herramientas o razonando la respuesta.
4. [`stores/asistente.js` (Pinia)]:
   - Gestión centralizada del estado: visibilidad (`isOpen`), lista reactiva de mensajes, bandera de carga (`cargando`), UUID de la conversación abierta (persistido en `localStorage`), listado del historial y acciones `enviarMensaje()`, `cargarConversacion()`, `abrirConversacion()`, `eliminarConversacion()` y `limpiarHistorial()` (que ahora solo vacía el chat y suelta el UUID, sin borrar nada).

---

## 8. Pruebas Automatizadas y Estrategia de Calidad (TDD)

El asistente cuenta con una suite completa de **21 pruebas automatizadas** en [`tests/Feature/AsistenteTest.php`], ejecutadas en un entorno 100% aislado en memoria (SQLite) para garantizar que la base de datos PostgreSQL de desarrollo no sea modificada ni corrompida:

```bash
docker compose -f avicola-backend-api/docker-compose.yml exec \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=:memory: \
  -e DB_URL= \
  api php artisan test --filter=AsistenteTest
```

### Cobertura de Pruebas

- **Composición del Prompt:** Verifica la presencia de las 4 capas y el nombre AviaCore.
- **Generación de Esquemas OpenAPI:** Comprueba que las herramientas produzcan el JSON Schema compatible con Gemini.
- **Inyección Anti-IDOR:** Valida que el registry inyecte el contexto fijo de sesión (`id_usuario`) a la herramienta. Que el orquestador **descarte** los argumentos de identidad que el modelo intente pasar (`id_usuario`, `email`…) se agregó después y se prueba en `BenchmarkOrquestadorTest`.
- **Atajo de Saludos y Saneamiento:** El atajo de saludos (sin llamar a Gemini) y el reemplazo de nombres internos de tools tienen pruebas propias en `AsistenteTest`.
- **Ejecución Unitaria de las Tools:** Verifica que cada herramienta operativa calcule y devuelva los datos correctos de aves, huevos, gastos, ubicación y alimentos; la herramienta de manuales técnicos se prueba en `RagTest`.
- **Resiliencia ante Fallos de Red / 404 / 503:** Verifica el comportamiento seguro y mensajes legibles cuando la API de Gemini no responde.
- **Prevención de Bucles Infinitos:** Garantiza que el orquestador se detenga limpiamente al alcanzar el límite de 3 iteraciones de Function Calling.
- **Endpoint HTTP Seguro:** Valida autenticación Sanctum y formato de respuesta JSON.

---

## 9. Hoja de Ruta: Evolución hacia Etapa 8 (RAG con pgvector)

La arquitectura actual de AviaCore fue concebida para integrarse directamente con la **Etapa 8: Base de Conocimiento RAG con `pgvector`**:

```mermaid
graph LR
    User([Pregunta Zootécnica del Usuario]) --> AviaCore[Orquestador AviaCore]
    AviaCore --> Check{¿Consulta Manuales?}
    Check -->|Sí| RAGTool[ConsultarManualesTecnicosRAGTool]
    Check -->|No| OperationalTools[Tools Operativas 1..5]
    
    RAGTool --> Embeddings[text-embedding-004 Gemini]
    Embeddings --> VectorSearch[(PostgreSQL 16 + pgvector)]
    VectorSearch -->|Top 3 Chunks por Similitud Coseno| RAGTool
    RAGTool --> LLMResponse[Respuesta con Citación Bibliográfica]
```

1. **Nuevo Modelo de Embeddings:** Inclusión de `text-embedding-004` en `GeminiApiClient` para generar vectores de 768 dimensiones.
2. **Tablas Vectoriales en PostgreSQL 16:**
   - `ctl_documentos_tecnicos`: Catálogo de manuales (Pato Pichiche, Chompipe, Gallina Criolla).
   - `mnt_documentos_fragmentos`: Fragmentos técnicos con columna `embedding vector(768)` e índice HNSW para búsqueda acelerada por similitud coseno (`<=>`).
3. **Nueva Tool OCP:** `ConsultarManualesTecnicosRAGTool` se registrará en `AppServiceProvider` sin tocar el código existente, dotando a AviaCore de conocimiento zootécnico profundo y con citación rigurosa de fuentes.
