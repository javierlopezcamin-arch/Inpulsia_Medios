# Inpulsia Medios

Plugin de WordPress para editar los medios de la biblioteca de forma masiva con un CSV: nombre de archivo, título, texto alternativo, leyenda y descripción.

## Uso

En **Medios → Inpulsia Medios**:

1. **Exportar**: descarga un CSV con los medios actuales. Se puede filtrar por tipo, por imágenes sin alt y por fecha de subida. La columna `usado_en` indica dónde aparece cada imagen (destacada, galería de WooCommerce, contenido y Elementor).
2. **Editar** el CSV con las indicaciones SEO.
3. **Importar**: sube el CSV, revisa la vista previa con sus avisos y aplica los cambios. Se procesan por lotes con barra de progreso.
4. **Historial**: deshace cualquiera de las últimas 20 importaciones.

## Formato del CSV

Separador `;` o `,`, en UTF-8 o Windows-1252 (Excel).

| Columna | Obligatoria | Notas |
|---|---|---|
| `ID` | Sí | ID del medio |
| `filename` | No | Sin extensión. Se normaliza (minúsculas, guiones, sin tildes) |
| `title` | No | Título del adjunto |
| `alt` | No | Texto alternativo (recomendado ≤ 125 caracteres) |
| `caption` | No | Leyenda |
| `description` | No | Descripción |

- Celda vacía: no se cambia.
- `[vaciar]`: borra el campo.
- También se aceptan cabeceras en español: `nombre`, `titulo`, `texto alternativo`, `leyenda`, `descripcion`.

## Renombrado de archivos

Al cambiar `filename` se renombran el original, las miniaturas y las versiones `-scaled`/`-rotated`. Las URLs se actualizan en entradas, páginas y datos de Elementor, y se regenera el CSS de Elementor. Las cachés de página externas (WP Rocket, LiteSpeed, CDN…) hay que vaciarlas a mano.

## Publicar una versión

1. Sube la versión en la cabecera y en `INPULSIA_MEDIOS_VERSION` de `inpulsia-medios.php`. Las dos tienen que coincidir.
2. Haz commit y push a `main`.

La GitHub Action [`release.yml`](.github/workflows/release.yml) detecta la versión nueva, genera el zip y publica la Release `vX.Y.Z` con la lista de commits como notas. Si la versión no ha cambiado, no hace nada.

Las webs con el plugin instalado verán la actualización en **Plugins**, o al instante con el enlace «Buscar actualizaciones». El repositorio debe ser público.

Si necesitas el zip en local, usa `./build.sh`.
