<?php

namespace Database\Seeders;

use App\Models\ComponenteTema;
use App\Models\ConfiguracionTema;
use Illuminate\Database\Seeder;

/**
 * Precarga el catálogo de componentes configurables y sus aspectos visuales por defecto,
 * respetando exactamente la paleta oficial documentada en DESIGN.md para modo claro
 * y su equivalente orgánico para modo nocturno. Idempotente (no sobrescribe personalizaciones).
 */
class TemaDefaultSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definicion() as $ordenComp => $comp) {
            $componente = ComponenteTema::updateOrCreate(
                ['clave' => $comp['clave']],
                [
                    'nombre' => $comp['nombre'],
                    'descripcion' => $comp['descripcion'],
                    'icono' => $comp['icono'],
                    'orden' => $ordenComp + 1,
                    'activo' => true,
                ]
            );

            foreach ($comp['aspectos'] as $ordenAsp => $asp) {
                foreach (['claro', 'nocturno'] as $modo) {
                    ConfiguracionTema::firstOrCreate(
                        [
                            'id_componente_tema' => $componente->id,
                            'modo' => $modo,
                            'aspecto' => $asp['aspecto'],
                        ],
                        [
                            'etiqueta' => $asp['etiqueta'],
                            'variable_css' => $asp['variable_css'],
                            'tipo_control' => $asp['tipo_control'] ?? ConfiguracionTema::CONTROL_COLOR,
                            'valor_actual' => $asp[$modo],
                            'valor_defecto' => $asp[$modo],
                            'orden' => $ordenAsp + 1,
                        ]
                    );
                }
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definicion(): array
    {
        return [
            [
                'clave' => 'botones',
                'nombre' => 'Botones de Acción',
                'descripcion' => 'Colores de botones principales (.btn-principal), secundarios (.btn-secundario) y destructivos.',
                'icono' => 'MousePointerClick',
                'aspectos' => [
                    ['aspecto' => 'primario_fondo', 'etiqueta' => 'Botón principal · Fondo', 'variable_css' => '--avicola-btn-primario-bg', 'claro' => '#244B36', 'nocturno' => '#3B7A57'],
                    ['aspecto' => 'primario_hover', 'etiqueta' => 'Botón principal · Hover', 'variable_css' => '--avicola-btn-primario-hover', 'claro' => '#1B3829', 'nocturno' => '#2F6346'],
                    ['aspecto' => 'primario_texto', 'etiqueta' => 'Botón principal · Texto', 'variable_css' => '--avicola-btn-primario-text', 'claro' => '#FFFFFF', 'nocturno' => '#FFFFFF'],
                    ['aspecto' => 'secundario_fondo', 'etiqueta' => 'Botón secundario · Fondo', 'variable_css' => '--avicola-btn-secundario-bg', 'claro' => '#FFFFFF', 'nocturno' => '#16221C'],
                    ['aspecto' => 'secundario_borde', 'etiqueta' => 'Botón secundario · Contorno', 'variable_css' => '--avicola-btn-secundario-border', 'claro' => '#244B36', 'nocturno' => '#7AA885'],
                    ['aspecto' => 'secundario_texto', 'etiqueta' => 'Botón secundario · Texto', 'variable_css' => '--avicola-btn-secundario-text', 'claro' => '#244B36', 'nocturno' => '#E8EFEA'],
                    ['aspecto' => 'destructivo_fondo', 'etiqueta' => 'Botón destructivo · Fondo', 'variable_css' => '--avicola-btn-destructivo-bg', 'claro' => '#244B36', 'nocturno' => '#991B1B'],
                    ['aspecto' => 'destructivo_hover', 'etiqueta' => 'Botón destructivo · Hover', 'variable_css' => '--avicola-btn-destructivo-hover', 'claro' => '#1B3829', 'nocturno' => '#7F1D1D'],
                ],
            ],
            [
                'clave' => 'formularios',
                'nombre' => 'Formularios (FormField)',
                'descripcion' => 'Apariencia de campos de captura en modales y login (fondo, texto, etiqueta y borde).',
                'icono' => 'FileInput',
                'aspectos' => [
                    ['aspecto' => 'fondo', 'etiqueta' => 'Fondo del campo', 'variable_css' => '--avicola-form-bg', 'claro' => '#FFFFFF', 'nocturno' => '#16221C'],
                    ['aspecto' => 'texto', 'etiqueta' => 'Texto ingresado', 'variable_css' => '--avicola-form-text', 'claro' => '#26332B', 'nocturno' => '#E8EFEA'],
                    ['aspecto' => 'etiqueta', 'etiqueta' => 'Color de etiqueta (Label)', 'variable_css' => '--avicola-form-label', 'claro' => '#526359', 'nocturno' => '#9BB2A4'],
                    ['aspecto' => 'borde', 'etiqueta' => 'Contorno del campo', 'variable_css' => '--avicola-form-border', 'claro' => '#E4E7DF', 'nocturno' => '#25382E'],
                ],
            ],
            [
                'clave' => 'filtros',
                'nombre' => 'Cajas de Texto y Filtros',
                'descripcion' => 'Estilos de FloatingInput, FloatingSelect y barras de filtrado (notch label y contornos).',
                'icono' => 'SlidersHorizontal',
                'aspectos' => [
                    ['aspecto' => 'fondo', 'etiqueta' => 'Fondo de caja de filtro', 'variable_css' => '--avicola-filter-bg', 'claro' => '#FFFFFF', 'nocturno' => '#16221C'],
                    ['aspecto' => 'notch', 'etiqueta' => 'Etiqueta flotante (Notch)', 'variable_css' => '--avicola-filter-notch', 'claro' => '#6F9678', 'nocturno' => '#7AA885'],
                    ['aspecto' => 'borde', 'etiqueta' => 'Contorno de filtro', 'variable_css' => '--avicola-filter-border', 'claro' => '#E4E7DF', 'nocturno' => '#25382E'],
                ],
            ],
            [
                'clave' => 'ribetes_contornos',
                'nombre' => 'Ribetes y Contornos',
                'descripcion' => 'Bordes neutros, acento salvia y anillos de enfoque (focus-ring) en todo el sistema.',
                'icono' => 'SquareDashed',
                'aspectos' => [
                    ['aspecto' => 'borde_neutro', 'etiqueta' => 'Contorno neutro global', 'variable_css' => '--avicola-borde', 'claro' => '#E4E7DF', 'nocturno' => '#25382E'],
                    ['aspecto' => 'salvia', 'etiqueta' => 'Acento Salvia (bordes e iconos)', 'variable_css' => '--avicola-salvia', 'claro' => '#6F9678', 'nocturno' => '#7AA885'],
                    ['aspecto' => 'salvia_hover', 'etiqueta' => 'Salvia Hover', 'variable_css' => '--avicola-salvia-hover', 'claro' => '#587A60', 'nocturno' => '#638D6E'],
                    ['aspecto' => 'ribete_foco', 'etiqueta' => 'Ribete de foco (Focus Ring)', 'variable_css' => '--avicola-ring-focus', 'claro' => '#6F9678', 'nocturno' => '#7AA885'],
                ],
            ],
            [
                'clave' => 'sombras',
                'nombre' => 'Sombras y Elevación',
                'descripcion' => 'Intensidad de sombras sutiles en tarjetas, barra superior y contenedores elevados.',
                'icono' => 'Layers',
                'aspectos' => [
                    ['aspecto' => 'sombra_xs', 'etiqueta' => 'Sombra sutil (shadow-xs)', 'variable_css' => '--avicola-shadow-xs', 'tipo_control' => ConfiguracionTema::CONTROL_SHADOW, 'claro' => '0 1px 2px 0 rgb(0 0 0 / 0.05)', 'nocturno' => '0 1px 3px 0 rgb(0 0 0 / 0.35)'],
                    ['aspecto' => 'sombra_card', 'etiqueta' => 'Sombra de tarjetas y modales', 'variable_css' => '--avicola-shadow-card', 'tipo_control' => ConfiguracionTema::CONTROL_SHADOW, 'claro' => '0 4px 12px -2px rgb(38 51 43 / 0.08)', 'nocturno' => '0 6px 16px -2px rgb(0 0 0 / 0.45)'],
                ],
            ],
            [
                'clave' => 'tarjetas',
                'nombre' => 'Tarjetas y Contenedores',
                'descripcion' => 'Superficies blancas de StatCard, SectionCard, DataTable y modales.',
                'icono' => 'CreditCard',
                'aspectos' => [
                    ['aspecto' => 'superficie', 'etiqueta' => 'Superficie principal (Tarjetas)', 'variable_css' => '--avicola-blanco', 'claro' => '#FFFFFF', 'nocturno' => '#16221C'],
                    ['aspecto' => 'superficie_alt', 'etiqueta' => 'Superficie alterna (Filas / Hover)', 'variable_css' => '--avicola-crema-surface', 'claro' => '#FAF8F5', 'nocturno' => '#1D2B24'],
                ],
            ],
            [
                'clave' => 'navegacion',
                'nombre' => 'Navegación (Navbar y Sidebar)',
                'descripcion' => 'Color corporativo primario (Bosque), estado activo del menú y resaltados.',
                'icono' => 'PanelLeft',
                'aspectos' => [
                    ['aspecto' => 'bosque', 'etiqueta' => 'Color primario (Bosque)', 'variable_css' => '--avicola-bosque', 'claro' => '#244B36', 'nocturno' => '#3B7A57'],
                    ['aspecto' => 'bosque_hover', 'etiqueta' => 'Primario Hover', 'variable_css' => '--avicola-bosque-hover', 'claro' => '#1B3829', 'nocturno' => '#2F6346'],
                    ['aspecto' => 'bosque_50', 'etiqueta' => 'Fondo ítem activo', 'variable_css' => '--avicola-bosque-50', 'claro' => '#F2F7F4', 'nocturno' => '#1E3328'],
                    ['aspecto' => 'bosque_100', 'etiqueta' => 'Resaltado suave (bosque-100)', 'variable_css' => '--avicola-bosque-100', 'claro' => '#E2EDE7', 'nocturno' => '#264234'],
                ],
            ],
            [
                'clave' => 'fondos',
                'nombre' => 'Fondos Generales y Tipografía',
                'descripcion' => 'Fondo ambiental de páginas (Crema) y tintas de lectura (Verdoso).',
                'icono' => 'PaintBucket',
                'aspectos' => [
                    ['aspecto' => 'fondo_general', 'etiqueta' => 'Fondo general (Viewport)', 'variable_css' => '--avicola-crema', 'claro' => '#F5F3EA', 'nocturno' => '#0F1713'],
                    ['aspecto' => 'tinta_principal', 'etiqueta' => 'Tipografía principal', 'variable_css' => '--avicola-verdoso', 'claro' => '#26332B', 'nocturno' => '#E8EFEA'],
                    ['aspecto' => 'tinta_secundaria', 'etiqueta' => 'Tipografía secundaria / Muted', 'variable_css' => '--avicola-verdoso-muted', 'claro' => '#526359', 'nocturno' => '#9BB2A4'],
                ],
            ],
            [
                'clave' => 'badges',
                'nombre' => 'Badges y Alertas',
                'descripcion' => 'Colores semánticos de advertencia (Huevo), tierra y errores destructivos.',
                'icono' => 'Tag',
                'aspectos' => [
                    ['aspecto' => 'exito_bg', 'etiqueta' => 'Fondo alerta / badge verde (Éxito)', 'variable_css' => '--avicola-exito-bg', 'claro' => '#DDF3E3', 'nocturno' => '#1B3B2B'],
                    ['aspecto' => 'exito_text', 'etiqueta' => 'Texto alerta / badge verde (Éxito)', 'variable_css' => '--avicola-exito-text', 'claro' => '#244B36', 'nocturno' => '#86EFAC'],
                    ['aspecto' => 'huevo', 'etiqueta' => 'Amarillo Huevo (Acento)', 'variable_css' => '--avicola-huevo', 'claro' => '#D9A441', 'nocturno' => '#E0B153'],
                    ['aspecto' => 'huevo_suave', 'etiqueta' => 'Fondo alerta preventiva', 'variable_css' => '--avicola-huevo-suave', 'claro' => '#FFF1CC', 'nocturno' => '#3D2E10'],
                    ['aspecto' => 'tierra', 'etiqueta' => 'Marrón Tierra', 'variable_css' => '--avicola-tierra', 'claro' => '#8B6548', 'nocturno' => '#B08463'],
                    ['aspecto' => 'destructivo', 'etiqueta' => 'Texto / Icono destructivo', 'variable_css' => '--avicola-destructivo', 'claro' => '#B91C1C', 'nocturno' => '#EF4444'],
                    ['aspecto' => 'destructivo_bg', 'etiqueta' => 'Fondo error / destructivo', 'variable_css' => '--avicola-destructivo-bg', 'claro' => '#FEF2F2', 'nocturno' => '#3B1515'],
                ],
            ],
        ];
    }
}

