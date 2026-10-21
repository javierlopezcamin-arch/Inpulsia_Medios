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

1. Sube la versión en la cabecera y en `INPULSIA_MEDIOS_VERSION` de `inpulsia-medios.php`.
2. Commit y push.
3. Ejecuta `./build.sh` para generar `../inpulsia-medios.zip`.
4. En GitHub, crea una Release con la etiqueta `vX.Y.Z` y adjunta el zip.

Las webs con el plugin instalado verán la actualización en **Plugins**, o al instante con el enlace «Buscar actualizaciones». El repositorio debe ser público.
