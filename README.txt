REPORTE DE TERRENO V3 - RECEPCION CENTRAL

Esta V3 agrega un servidor central real para recibir los reportes.
El telefono envia el reporte al servidor; si no hay conexion, lo guarda
localmente y reintenta al volver la conexion.

PRUEBA LOCAL:
1. Instale Node.js.
2. Abra una terminal en esta carpeta.
3. Ejecute: npm install
4. Ejecute: npm start
5. En el computador abra: http://localhost:3000
6. En un telefono conectado a la misma Wi-Fi abra:
   http://IP-DEL-COMPUTADOR:3000

Los reportes quedan en data/reportes.json y las fotos en uploads/.

Para uso por Internet desde varios lugares se debe publicar este servidor
en un hosting con HTTPS y almacenamiento persistente.
