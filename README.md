# Inpulsia Medios

Plugin de WordPress para editar los medios de la biblioteca de forma masiva: nombre de archivo, título, texto alternativo, leyenda y descripción. Funciona con CSV/Excel, con edición directa en tabla, con sugerencias de IA y por WP-CLI.

Requiere WordPress 6.2 y PHP 7.4 o superiores.

## Uso

En **Medios → Inpulsia Medios**. La pestaña **Inicio** muestra el estado de la web (imágenes en uso, sin alt, en formatos pesados), una guía de 4 pasos y preguntas frecuentes; cada pestaña tiene además ayuda en el botón «Ayuda» de WordPress.

- **Importar**: sube un CSV o `.xlsx`, revisa la vista previa con sus avisos y aplica los cambios. Se procesan por lotes con barra de progreso.
- **Editar**: tabla de 20 imágenes por página con búsqueda y filtros por uso (en uso / sin uso), formato (JPG, PNG, WebP, pesados), peso (más de 300 KB, 1 MB o 2 MB, de mayor a menor) y «solo sin alt». Los cambios se revisan en la misma vista previa antes de aplicarse. El botón **IA** propone alt, nombre y título para una imagen; también se puede pedir para todas las seleccionadas.
- **Optimizar** (dentro de Editar): convierte a JPG o WebP, limita el ancho, recomprime y quita los metadatos EXIF. Opcionalmente pone nombre amigable y alt con IA cuando faltan o el nombre es de cámara (`IMG_2041`, `DSC0099`, `WhatsApp Image…`). Se aplica a las imágenes seleccionadas o a todas las del filtro, por ejemplo **En uso en la web**.
- **Exportar**: CSV de la biblioteca filtrado por tipo, imágenes sin alt o fecha. La columna `usado_en` indica dónde aparece cada imagen (destacada, galería de WooCommerce, contenido y Elementor).
- **Historial**: deshace cualquiera de las últimas 20 importaciones o ediciones.
- **Redirecciones**: lista de redirecciones 301 creadas al renombrar archivos, con sus visitas.
- **Ajustes** (solo administradores): clave de la API de Claude, modelo y contexto SEO de la web.

## Formato del CSV / Excel

Separador `;` o `,`, en UTF-8 o Windows-1252. En Excel se lee la primera hoja.

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

## Qué pasa al renombrar un archivo

1. Se renombran el original, las miniaturas, las versiones `-scaled`/`-rotated` y las copias WebP/AVIF de los optimizadores (`foto.jpg.webp`, `foto.webp`, `foto.avif`).
2. Se actualizan las URLs en entradas, páginas, extractos, metadatos (ACF, Elementor, constructores), metadatos de términos y opciones (Customizer, widgets), respetando los datos serializados.
3. Se crea una redirección 301 de cada URL antigua a la nueva. Funciona cuando las peticiones a archivos inexistentes llegan a WordPress, que es lo habitual en Apache y en la mayoría de configuraciones de Nginx.
4. Al terminar se vacían las cachés de WP Rocket, LiteSpeed, W3 Total Cache, WP Super Cache, SiteGround, WP Fastest Cache, Breeze y Elementor. Las cachés de CDN externos hay que vaciarlas a mano.

Al cambiar el alt también se actualiza en las imágenes ya insertadas en Gutenberg o el editor clásico (`<img class="wp-image-ID">`). Elementor lo lee siempre del medio.

## Optimización de imágenes

- Los PNG, WebP y GIF estáticos se convierten al formato elegido (JPG por defecto, o WebP). Los que tienen píxeles transparentes, como los logos, se mantienen en su formato y los GIF animados no se tocan.
- La imagen se limita al ancho máximo (2560 px por defecto) y se recomprime (calidad 82). Se corrige la orientación EXIF y se regeneran las miniaturas.
- Si el resultado no pesa al menos un 5 % menos que el original, la imagen se deja como está. En gráficos planos, un PNG suele pesar menos que un JPG.
- Las URLs antiguas se reemplazan en todo el sitio (incluidos los fondos de Elementor, cuyo CSS se regenera) y redirigen con 301 a las nuevas, igual que al renombrar.
- La opción **Borrar del servidor la imagen anterior al sustituirla** elimina los archivos antiguos al momento para dejar el hosting limpio (la copia de seguridad, si está activada, sigue permitiendo deshacer).
- Si no, los archivos antiguos se conservan 15 días tras renombrar u optimizar, para que ninguna caché de servidor, CDN o enlace externo muestre una imagen rota; una tarea diaria los borra después. En Ajustes se pueden borrar antes.
- Al terminar se comprueba que la imagen nueva se puede abrir desde la web (y se corrigen sus permisos si hace falta). Si la web pasa por Cloudflare sin su plugin, se avisa para vaciar su caché.
- Con la copia de seguridad activada, los archivos originales se guardan en `uploads/inpulsia-medios-backups/` y la optimización se puede deshacer desde Historial. En Ajustes se pueden borrar las copias de más de 30 días.
- Si la web ya usa ShortPixel, Imagify, EWWW, Smush u Optimole, se muestra un aviso para no comprimir dos veces.

El filtro **En uso en la web** detecta imágenes destacadas, galerías de WooCommerce, logo e icono del sitio, imágenes insertadas en el contenido y cualquier URL de `uploads` en Elementor, metadatos u opciones. El resultado se guarda una hora; **Recalcular uso** lo actualiza. Los campos de imagen de ACF que solo guardan el ID no se detectan.

## Sugerencias con IA

En **Ajustes**, añade una clave de la API de Claude. Es más seguro definirla en `wp-config.php`:

```php
define( 'INPULSIA_MEDIOS_CLAUDE_KEY', 'sk-ant-...' );
```

Cada sugerencia envía la imagen (tamaño `large`), el contexto SEO de Ajustes y las páginas donde aparece. Las propuestas solo rellenan los campos de la tabla; nada se guarda hasta revisar y aplicar.

## WP-CLI

```bash
wp inpulsia-medios export medios.csv --missing-alt --usage
wp inpulsia-medios import medios.xlsx --dry-run
wp inpulsia-medios import medios.xlsx --yes
wp inpulsia-medios history
wp inpulsia-medios undo <token>
```

## Desarrollo

Tests con PHPUnit y el entorno oficial de WordPress:

```bash
composer install
WP_CORE_DIR=/ruta/a/wordpress WP_DB_NAME=wordpress_test WP_DB_USER=root WP_DB_PASS=root vendor/bin/phpunit
```

Análisis estático (seguridad de WordPress, SQL preparado y compatibilidad con PHP 7.4):

```bash
vendor/bin/phpcs
```

Sin MySQL se puede usar SQLite: copia el drop-in del plugin `sqlite-database-integration` a `wp-content/db.php` de esa instalación y define `WP_SQLITE_DIR` con una carpeta donde guardar la base de datos.

## Publicar una versión

1. Sube la versión en la cabecera y en `INPULSIA_MEDIOS_VERSION` de `inpulsia-medios.php`. Las dos tienen que coincidir.
2. Haz commit y push a `main`.

La GitHub Action [`release.yml`](.github/workflows/release.yml) ejecuta el análisis estático y los tests con PHP 7.4 y 8.4 sobre MySQL. Si pasan y la versión es nueva, genera el zip y publica la Release `vX.Y.Z`. Las webs con el plugin instalado verán la actualización en **Plugins**, o al instante con el enlace «Buscar actualizaciones».

Para generar el zip en local: `./build.sh`.
