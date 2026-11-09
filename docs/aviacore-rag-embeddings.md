# Base de Conocimiento RAG y Búsqueda Vectorial (pgvector) - AviaCore

> **Proyecto:** Granja Avícola Inteligente (Sistema de Control Integral)  
> **Componente:** Sistema RAG (Retrieval-Augmented Generation) & Embeddings  
> **Ubicación:** `avicola-backend-api` & `avicola-frontend`  
> **Estándar:** Spec-Driven Development (SDD) & Clean Architecture  
> **Fecha de Elaboración:** 09 de Noviembre de 2026  

---

# PARTE 1: Explicación General en Lenguaje Natural

## 1. ¿Qué es RAG y por qué lo necesita la Granja?

Imaginemos que contratas a un zootecnista recién graduado con excelente memoria y vocabulario (**el Modelo de Inteligencia Artificial**), pero que no conoce las directrices particulares ni los manuales sanitarios específicos redactados para nuestra granja.

Si un operador le pregunta:  
*`"¿Por qué no podemos meter los patos pichiches al mismo corral de las gallinas criollas?"`*

Un modelo de IA genérico podría dar una respuesta vaga, o peor aún, **alucinar** (inventar datos o dar recomendaciones erróneas sobre dosis de medicamentos o requerimientos nutricionales).

Para solucionar esto, aplicamos la técnica **RAG (Retrieval-Augmented Generation / Generación Aumentada por Recuperación)**:

1. Ponemos a disposición del asistente una **Biblioteca Oficial de la Granja** (manuales técnicos verificados).
2. Cuando el operador hace una pregunta zootécnica, el asistente **no responde de memoria**.
3. Primero va a la biblioteca, busca exactamente el capítulo y la página relevante (**Retrieval**).
4. Lee ese fragmento oficial (**Augmented**).
5. Con el texto en mano, redacta la respuesta en lenguaje claro y cita la fuente oficial (**Generation**).

```
[Pregunta del Operador]
          │
          ▼
┌──────────────────────────────────┐
│  1. BUSCAR EN LA BIBLIOTECA      │ ──► Revisa los Manuales Zootécnicos de la Granja
└──────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────┐
│  2. ENCONTRAR EL FRAGMENTO EXACTO│ ──► "Los patos transmiten exceso de humedad y parásitos..."
└──────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────┐
│  3. REDACTAR CON CITA OFICIAL    │ ──► "Según el Manual Técnico de Crianza del Pato Pichiche..."
└──────────────────────────────────┘
```

---

## 2. ¿Qué son los Embeddings y la Búsqueda Vectorial?

Una búsqueda tradicional en bases de datos busca **palabras idénticas** (por ejemplo: `WHERE texto LIKE '%pato%'`). Si el operador escribe *"ave acuática con diarrea"* y el manual dice *"enteritis vírica en anátidos"*, el buscador tradicional no encuentra nada porque ninguna palabra coincide.

Los **Embeddings** solucionan este problema convirtiendo el **significado de las palabras** en una lista de números (un vector de 768 dimensiones). Es como ubicar los conceptos en un mapa tridimensional de significados:

* Las frases *"cuidados alimenticios para patos"* y *"nutrición y raciones en anátidos"* tienen palabras totalmente distintas, pero en el mapa de embeddings **están muy cerca unas de otras**.
* La herramienta especializada en PostgreSQL (**`pgvector`**) actúa como un compás de alta velocidad: calcula la distancia matemática entre la duda del operador y los manuales almacenados, recuperando los párrafos más cercanos conceptualmente en milisegundos.

---

## 3. El Viaje de una Consulta Zootécnica en 6 Pasos Simples

```
[Usuario pregunta]
        │
        ▼
[1. Gemini detecta la intención zootécnica]
        │  "Necesito literatura técnica, no datos contables"
        ▼
[2. Gemini solicita Function Call: 'consultar_manuales_tecnicos']
        │
        ▼
[3. La Tool vectoriza la duda del usuario]
        │  Convierte la pregunta a 768 coordenadas numéricas (Embedding)
        ▼
[4. PostgreSQL (pgvector) hace la búsqueda semántica]
        │  Compara distancias (<=>) y rescata los 2 o 3 párrafos más afines
        ▼
[5. Se devuelven los fragmentos crudos del manual al LLM]
        │  "Toma el texto oficial del Manual del Pato Pichiche..."
        ▼
[6. Gemini redacta la respuesta final con pedagogía y citas]
        │  - Traduce el texto técnico a un tono cercano y profesional.
        │  - Cita explícitamente la fuente oficial.
        ▼
[Operador lee en pantalla una respuesta humana, verídica y sin alucinaciones]
```

### ¿Por qué este diseño es tan potente y seguro?

1. **La Base de Datos solo guarda texto y vectores puros:**  
   PostgreSQL y `pgvector` no "saben hablar" ni improvisan; actúan como una biblioteca matemática ultra-rápida que calcula distancias geométricas y dice: *"estos dos párrafos del manual tienen un 75% de afinidad conceptual con lo que preguntó el operario"*.
2. **El LLM no inventa ni alucina:**  
   Al recibir el fragmento oficial dentro de la conversación como contexto de la herramienta, el LLM no tiene que adivinar dosis ni requerimientos proteicos de memoria; lee el párrafo exacto y se ciñe estrictamente a él.
3. **Aporta el toque humano, pedagógico y de formato:**  
   En vez de arrojarle al operario un bloque de texto denso o un PDF crudo, Gemini:
   - Extrae los puntos clave y los organiza con viñetas limpias (`•`).
   - Explica el *por qué* zootécnico (por ejemplo: *"los patos necesitan bebederos hondos para humedecer la comida y no atragantarse"*).
   - Añade el trato respetuoso y profesional en modo operativo agropecuario.
   - Cita la fuente oficial: *"Según el Manual Técnico de Crianza y Sanidad del Pato Pichiche..."*.

### Desglose Paso a Paso de una Consulta Real

1. **Paso 1: La duda en el campo:** El operario pregunta en el widget de AviaCore:  
   *`"¿Qué pasa si un chompipe tiene diarrea y la cabeza oscura?"`*
2. **Paso 2: Detección y Tool Call:** Gemini reconoce que es una consulta sanitaria/patológica y solicita ejecutar `consultar_manuales_tecnicos`.
3. **Paso 3: Generación del vector:** El backend toma la pregunta y genera su vector numérico de 768 números utilizando el modelo `gemini-embedding-001`.
4. **Paso 4: Búsqueda geométrica en PostgreSQL:** El servidor ejecuta una consulta en la base de datos usando `pgvector`. Este calcula qué párrafos de los manuales están más cerca geométricamente de la duda.
5. **Paso 5: Recuperación del párrafo exacto:** La base de datos encuentra el fragmento de la *Guía Zootécnica de Pavos y Chompipes* correspondiente a la sección *Bioseguridad y Prevención de Histomoniasis (Cabeza Negra)* con una similitud del **72.4%**.
6. **Paso 6: Redacción con respaldo técnico:** AviaCore recibe el texto del manual y redacta la respuesta explicando que se trata de Histomoniasis por el parásito *Heterakis gallinarum*, transmitido habitualmente por gallinas.
7. **Paso 7: Transparencia total en pantalla:** El operador recibe la respuesta en el chat con el avatar de la gallina ([`GallinaIcon.vue`]), la cita bibliográfica explícita y una insignia distintiva que dice: `[⚙ Manuales técnicos zootécnicos]`.

---

## 4. Biblioteca Zootécnica Oficial Cargada en el Sistema

Actualmente, la base vectorial de la granja cuenta con **3 manuales maestros** divididos en fragmentos especializados:

| Manual Técnico | Especie | Ejes Temáticos Clave |
|---|---|---|
| **Manual Técnico de Crianza y Sanidad del Pato Pichiche** | `Pato` | • Proteína cruda requerida (18-20% iniciación, 15-16% mantenimiento).<br>• Necesidad vital de bebederos profundos para humedecer alimentos y evitar atragantamientos.<br>• Prevención de Aspergilosis con camas secas y desparasitación trimestral.<br>• Regla biológica: separación obligatoria de gallinas criollas. |
| **Guía Zootécnica de Pavos y Chompipes** | `Chompipe` | • Altísima exigencia de proteína inicial (26-28% primeras 4 semanas).<br>• Niveles de calcio, fósforo y manganeso para evitar perosis (deformación de patas).<br>• Letalidad de la Histomoniasis ("cabeza negra") y prohibición de convivencia con gallinas.<br>• Área mínima de 2 m² por ave y perchas bajas para cuidar la quilla. |
| **Manual de Manejo Integral de la Gallina Criolla** | `Gallina` | • Proporción de 1 nidal por cada 4 a 5 gallinas con cama limpia de viruta.<br>• Recolección 2 veces al día para evitar roturas y cloquez.<br>• Plan vacunal: Newcastle (La Sota), viruela aviar y bronquitis.<br>• Baños de arena y ceniza con tierra de diatomeas contra ácaros y piojos.<br>• Vacío sanitario de 14 días entre lotes. |

---

# PARTE 2: Arquitectura y Especificación Técnica

## 1. Arquitectura de Datos y Extensión `pgvector`

La base de datos PostgreSQL 16 utiliza la extensión oficial `vector` (`pgvector 0.8.7`). Las tablas están diseñadas siguiendo las convenciones estrictas del proyecto:

```
┌─────────────────────────────────────────────────────────────────┐
│                     ctl_documentos_tecnicos                     │
├─────────────────────────┬───────────────────────────────────────┤
│ id_documento_tecnico    │ BIGSERIAL (PK)                        │
│ titulo                  │ VARCHAR(150)                          │
│ especie                 │ VARCHAR(50) NULL                      │
│ descripcion             │ TEXT NULL                             │
│ autor                   │ VARCHAR(100) NULL                     │
│ version                 │ VARCHAR(20) DEFAULT '1.0'             │
│ activo                  │ BOOLEAN DEFAULT TRUE                  │
│ timestamps              │ created_at, updated_at                │
└─────────────────────────┴───────────────────────────────────────┘
                                   │ 1
                                   │
                                   │ N (CASCADE)
                                   ▼
┌─────────────────────────────────────────────────────────────────┐
│                    mnt_documentos_fragmentos                    │
├─────────────────────────┬───────────────────────────────────────┤
│ id_fragmento            │ BIGSERIAL (PK)                        │
│ id_documento_tecnico    │ BIGINT (FK -> ctl_documentos_tecnicos)│
│ titulo_seccion          │ VARCHAR(150)                          │
│ contenido               │ TEXT                                  │
│ metadata                │ JSON NULL                             │
│ embedding               │ VECTOR(768) [PostgreSQL] / TEXT [Test]│
│ timestamps              │ created_at, updated_at                │
└─────────────────────────┴───────────────────────────────────────┘
   ▲
   └── Índice HNSW: USING hnsw (embedding vector_cosine_ops)
```

### Migración con Compatibilidad Dual (`pgsql` / `sqlite`)

Para garantizar que la suite de pruebas automatizadas se ejecute al 100% en memoria con SQLite sin dependencias externas, la migración [`2026_11_09_180000_create_documentos_tecnicos_tables.php`] detecta dinámicamente el motor de base de datos:

```php
if ($driver === 'pgsql') {
    DB::statement('CREATE EXTENSION IF NOT EXISTS vector;');
    $table->addColumn('vector', 'embedding', ['dimensions' => 768])->nullable();
} else {
    $table->text('embedding')->nullable();
}
```

Además, en PostgreSQL se crea un índice de vecinos más cercanos **HNSW (Hierarchical Navigable Small World)** optimizado para distancia coseno:

```sql
CREATE INDEX IF NOT EXISTS idx_fragmentos_embedding_hnsw 
ON mnt_documentos_fragmentos USING hnsw (embedding vector_cosine_ops);
```

---

## 2. Generación de Embeddings (`GeminiApiClient`)

El cliente HTTP [`GeminiApiClient.php`] invoca el endpoint oficial `embedContent`:

```
POST https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent?key={API_KEY}
```

### Configuración Dimensional en `config/gemini.php`:

```php
'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
'embedding_dimensions' => (int) env('GEMINI_EMBEDDING_DIMENSIONS', 768),
```

### Payload Enviado:

```json
{
  "model": "models/gemini-embedding-001",
  "content": {
    "parts": [
      { "text": "Texto del fragmento o pregunta..." }
    ]
  },
  "outputDimensionality": 768
}
```

> **Alineación de Dimensiones:** `gemini-embedding-001` genera por defecto 3,072 dimensiones. Al especificar `outputDimensionality: 768`, la API de Google trunca y normaliza el vector matemáticamente a exactamente 768 dimensiones, logrando compatibilidad perfecta con nuestra columna `vector(768)` en PostgreSQL sin pérdida de precisión semántica.

---

## 3. Servicio de Recuperación Vectorial (`VectorStoreService`)

La clase [`VectorStoreService.php`] encapsula tanto la persistencia de fragmentos como la búsqueda semántica.

### Consulta Nativa en PostgreSQL (pgvector):

```php
$vectorStr = '[' . implode(',', $vectorQuery) . ']';

$query = DB::table('mnt_documentos_fragmentos as f')
    ->join('ctl_documentos_tecnicos as d', 'd.id_documento_tecnico', '=', 'f.id_documento_tecnico')
    ->where('d.activo', true)
    ->select([
        'f.id_fragmento',
        'f.titulo_seccion',
        'f.contenido',
        'd.titulo as documento_titulo',
        'd.especie as documento_especie',
    ])
    ->selectRaw('(f.embedding <=> ?::vector) as distancia', [$vectorStr]);

if ($especie !== null) {
    $query->where(function ($q) use ($especie) {
        $q->where('d.especie', $especie)->orWhereNull('d.especie');
    });
}

return $query->orderBy('distancia', 'asc')->limit($limite)->get();
```

* **Operador `<=>`:** Calcula la **distancia coseno** entre el vector de búsqueda y el vector del fragmento (0 = vectores idénticos, 2 = opuestos).
* **Métrica de Similitud:** Se calcula como `similitud = 1 - distancia`.

### Fallback Matemático en SQLite (Testing):

Para entornos de testing con base de datos `:memory:`, [`VectorStoreService`] calcula la distancia coseno en PHP aplicando la fórmula matemática canónica:

$$\text{Similitud Coseno}(A, B) = \frac{A \cdot B}{\|A\| \|B\|} = \frac{\sum_{i=1}^{n} A_i B_i}{\sqrt{\sum_{i=1}^{n} A_i^2} \sqrt{\sum_{i=1}^{n} B_i^2}}$$

$$\text{Distancia Coseno} = 1 - \text{Similitud Coseno}$$

Esto asegura que las pruebas unitarias y de integración produzcan resultados idénticos a PostgreSQL sin dependencias externas.

---

## 4. Pipeline de Ingesta de Manuales (`IngestaManualesService`)

La ingesta procesa y vectoriza los documentos técnicos mediante [`IngestaManualesService.php`]:

```
[Definición de Manuales]
           │
           ▼
[Creación / Actualización en ctl_documentos_tecnicos]
           │
           ▼ (Por cada sección)
[Concatenación: Título + "\n\n" + Contenido]
           │
           ▼
[Llamada a GeminiApiClient::generarEmbedding (768 floats)]
           │
           ▼
[Persistencia en mnt_documentos_fragmentos con VectorStoreService]
```

### Ejecución de la Ingesta

El sistema proporciona dos vías operativas para ejecutar la ingesta:

1. **Comando Artisan:**
   ```bash
   php artisan avicola:ingestar-manuales
   ```
   *(Soporta la opción `--fake` para generar vectores deterministas en entornos sin conexión a internet).*
2. **Seeder de Base de Datos:**
   ```bash
   php artisan db:seed --class=DocumentosTecnicosSeeder
   ```

---

## 5. Herramienta RAG (`ConsultarManualesTecnicosRAGTool`)

Implementa el contrato formal [`AsistenteToolInterface`]:

```php
namespace App\Services\Asistente\Tools;

class ConsultarManualesTecnicosRAGTool implements AsistenteToolInterface
{
    public function nombre(): string
    {
        return 'consultar_manuales_tecnicos';
    }

    public function descripcion(): string
    {
        return 'Consulta la base de conocimiento técnico y manuales zootécnicos sobre crianza, nutrición, sanidad, prevención de enfermedades y bioseguridad de aves (gallinas criollas, chompipes/pavos y patos pichiches). Debe utilizarse siempre que el usuario haga preguntas sobre cuidados, requerimientos de alimentación, enfermedades o recomendaciones biológicas.';
    }

    public function esquema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'consulta' => [
                    'type' => 'STRING',
                    'description' => 'Texto descriptivo de la pregunta o duda técnica del usuario.',
                ],
                'especie' => [
                    'type' => 'STRING',
                    'description' => 'Filtro opcional por especie de ave (Gallina, Chompipe, Pato).',
                ],
            ],
            'required' => ['consulta'],
        ];
    }
}
```

### Principio Open-Closed (OCP)

La herramienta se registra en [`AppServiceProvider.php`]:

```php
private const ASISTENTE_TOOLS = [
    ConsultarInventarioAvesTool::class,
    ConsultarProduccionHuevosTool::class,
    ConsultarGastosPeriodoTool::class,
    ConsultarUbicacionAveTool::class,
    ConsultarRacionesAlimentoTool::class,
    ConsultarManualesTecnicosRAGTool::class, // <-- Herramienta RAG incorporada
];
```

El orquestador (`AsistenteOrquestadorService`) y el controlador (`AsistenteController`) permanecen inalterados.

---

## 6. Instrucción Zootécnica en el System Prompt (`PromptComposer`)

En [`PromptComposer.php`], la **Capa de Dominio** fue enriquecida para instruir a Gemini sobre cómo manejar la literatura técnica recuperada:

```text
REGLAS DEL DOMINIO AVÍCOLA Y MANUALES TÉCNICOS:
- La granja se organiza físicamente en Casitas (instalaciones) y Divisiones (corrales independientes).
- Especies manejadas: Gallina, Chompipe y Pato. Rige una estricta regla biológica de separación de especies por división (los patos por exceso de humedad y parásitos; los chompipes por su extrema vulnerabilidad a la Histomoniasis transmitida por gallinas).
- Postura de huevos: los huevos aprovechables se calculan como (recolectados - rotos).
- Consultas zootécnicas y sanitarias: ante preguntas sobre crianza, alimentación, prevención de enfermedades, nidos o bioseguridad, utiliza la herramienta `consultar_manuales_tecnicos` y cita siempre de forma explícita el nombre del manual técnico consultado (ej. "Según el Manual Técnico de Crianza y Sanidad del Pato Pichiche...").
- Distinción entre datos operativos y literatura: si el usuario pregunta datos numéricos de la granja (aves vivas, gastos, huevos recolectados), usa las tools operativas; si pregunta cómo criar o alimentar, usa los manuales técnicos; si combina ambas cosas, invoca ambas herramientas y sintetiza la respuesta.
- Regla de cero resultados: si una consulta no arroja registros, explícalo de manera constructiva y zootécnicamente clara en vez de un simple "no encontré nada".
```

---

## 7. Pruebas Automatizadas y Validación (TDD)

El subsistema RAG cuenta con **5 pruebas automatizadas exhaustivas** en [`tests/Feature/RagTest.php`]:

1. `test_modelos_documento_tecnico_y_fragmentos_persisten_correctamente`: Valida la persistencia en base de datos y relaciones Eloquent.
2. `test_gemini_api_client_genera_embedding_correctamente`: Valida la invocación HTTP del endpoint de embedding con simulación controlada (`Http::fake`).
3. `test_vector_store_service_guarda_y_busca_fragmentos_similares`: Valida el cálculo de similitud coseno y ordenamiento por relevancia.
4. `test_ingesta_manuales_service_crea_catalogo_y_fragmentos`: Valida la ingesta estructurada de los 3 manuales maestros y sus fragmentos.
5. `test_consultar_manuales_tecnicos_rag_tool_ejecuta_correctamente`: Valida el ciclo completo de la herramienta RAG recibiendo preguntas en lenguaje natural y filtrando por especie.

```bash
docker compose -f avicola-backend-api/docker-compose.yml exec \
  -e DB_CONNECTION=sqlite \
  -e DB_DATABASE=:memory: \
  -e DB_URL= \
  api php artisan test --filter=RagTest
```

**Resultado:** 5 pruebas pasadas (21 aserciones).  
**Suite Global:** 100 pruebas pasadas (787 aserciones) con 0 fallos.

