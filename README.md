# Reporte de Terreno — Inversiones y Mantenimiento MYC SpA

Aplicación web progresiva (PWA) con soporte **Offline (Sin Señal)** para captura de reportes en terreno, gestión de **Logística**, control interno de **Horas Extra / Emergencia** y generación automática de **Fichas PDF** y **Cotizaciones Oficiales en Excel (`.xlsx`)** para **Inversiones y Mantenimiento MYC SpA** (`https://reporte.mycmantencion.cl/`).

## Estructura del Proyecto

- **`subir-a-cpanel/`**: Archivos de producción listos para desplegar en `public_html/reporte-terreno` en cPanel (Vínculo.cl):
  - `index.html`: Aplicación principal (PWA instalable, compresión automática de fotos, cola offline en IndexedDB, panel oculto de Logística, generador nativo `.xlsx` formato Cotización MYC SpA y Ficha PDF).
  - `api.php`: Backend nativo en PHP con folios cortos correlativos (`RT-0001`), subida escalonada de fotografías (`< 1 MB` por petición compatible con proxy Vínculo.cl), autenticación por PIN y edición/eliminación de reportes.
  - `sw.js`: Service Worker (`v9`) con estrategia *Network-First con timeout de 3.5s* y respaldo offline completo.
  - `manifest.json`, `icon-192.png`, `icon-512.png`: Configuración de instalación en Android (Chrome) y iPhone (Safari).
  - `instructivo.html`: Presentación interactiva con Modo Video Automático y versión imprimible para trabajadores.
  - `.htaccess`: Redirección HTTPS y control de caché para actualizaciones instantáneas.
