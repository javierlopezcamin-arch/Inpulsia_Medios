# Inpulsia Medios

Plugin de WordPress para editar los medios de la biblioteca de forma masiva: nombre de archivo, título, texto alternativo, leyenda y descripción. Funciona con CSV/Excel, con edición directa en tabla, con sugerencias de IA y por WP-CLI.

Requiere WordPress 6.2 y PHP 7.4 o superiores.

## Uso

En **Medios → Inpulsia Medios**:

- **Importar**: sube un CSV o `.xlsx`, revisa la vista previa con sus avisos y aplica los cambios. Se procesan por lotes con barra de progreso.
- **Editar**: tabla de 20 imágenes por página con búsqueda y filtro «solo sin alt». Los cambios se revisan en la misma vista previa antes de aplicarse. El botón **IA** propone alt, nombre y título para una imagen; también se puede pedir para todas las seleccionadas.
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

Sin MySQL se puede usar SQLite: copia el drop-in del plugin `sqlite-database-integration` a `wp-content/db.php` de esa instalación y define `WP_SQLITE_DIR` con una carpeta donde guardar la base de datos.

## Publicar una versión

1. Sube la versión en la cabecera y en `INPULSIA_MEDIOS_VERSION` de `inpulsia-medios.php`. Las dos tienen que coincidir.
2. Haz commit y push a `main`.

La GitHub Action [`release.yml`](.github/workflows/release.yml) ejecuta los tests con PHP 7.4 y 8.4 sobre MySQL. Si pasan y la versión es nueva, genera el zip y publica la Release `vX.Y.Z`. Las webs con el plugin instalado verán la actualización en **Plugins**, o al instante con el enlace «Buscar actualizaciones».

Para generar el zip en local: `./build.sh`.
