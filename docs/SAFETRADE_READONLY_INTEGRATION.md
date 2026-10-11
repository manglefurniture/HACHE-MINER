# SafeTrade: integración de solo lectura (fase preparatoria)

Estado: **pendiente de autenticación y verificación real de endpoints**. No declarar funcional la integración hasta superar estas comprobaciones.

## Objetivo

Conciliar en una única operación económica la minería de Hache, Interactive y Guanabacoa. Las organizaciones son límites operativos de instancias de SaladCloud, no centros de beneficio separados. Recuperar desde SafeTrade los depósitos PRL, ejecuciones PRL/USDT, comisiones, saldos y retiros; comparar con la producción PRL verificada y los costos consumidos de SaladCloud.

## Modelo de seguridad obligatorio

- API Key generada por el propietario con **Trading y Withdraw desactivados**. Una API Key sin esas casillas no equivale por sí sola a una confirmación de permisos efectivos: probar únicamente lecturas.
- Nunca copiar API Key ni API Secret a GitHub, issues, PR, logs, stdout, URL, capturas, mensajes ni archivos públicos. No pedir al propietario que las pegue en el chat.
- Reutilizar el almacén cifrado existente `secret_store` y las funciones `miner_get_secret` / `miner_put_secret` de `app/core.php`; nombres previstos: `safetrade:api-key` y `safetrade:api-secret`.
- Antes de cargar secretos, verificar la copia de seguridad recuperable de `master.key` y permisos privados; nunca imprimir valores o ciphertexts.
- Diseñar un mecanismo de introducción por canal privado autenticado y protegido por CSRF, o stdin en terminal seguro sin argumentos ni historial. No desplegar capturas de credenciales en navegador. No usar privilegios root generales desde `deploy-hache`.
- Solicitudes HTTP únicamente a dominios oficiales verificados con HTTPS y timeout corto; firma/autenticación acorde con documentación vigente, sin registrar cabeceras ni respuestas sensibles. No implementar llamadas POST de órdenes/retiros.
- No rotar ni modificar credenciales de SaladCloud, ni reglas de auto-reallocate, ni servicios de minería.

## Contrato de conciliación

1. Depósito PRL: guardar ID de operación y/o txid, monto, comisión y fecha; enlazar con transferencia Pearl; no contabilizar como ingreso USD.
2. Venta ejecutada PRL/USDT: guardar identificador de ejecución único, cantidad PRL, precio, USDT bruto, comisión y USDT neto. Órdenes abiertas/canceladas **no** son venta realizada.
3. Retiros USDT: son movimientos de fondos ya obtenidos, **no** ingresos adicionales; deduplicar por identificador.
4. Balances PRL/USDT: son fotografías del estado, **no** flujos; nunca sumar snapshots como ingresos.
5. Costos Salad: tomar consumo efectivo confirmado de Credits/Usage o cortes conciliados. Las recargas prepagadas tampoco se suman de nuevo al consumo como gasto doble.
6. PRL producidos: pagos de Kryptex conciliados por txid con entradas on-chain de PearlTrack, más pendientes por separado; no duplicar ni inventar entradas de otras direcciones.
7. Toda transacción se guarda de forma idempotente usando proveedor+identificador y timestamp UTC; interfaz America/Cancun. Mantener cursores/paginación; mostrar rango y cobertura incompleta.

## Validaciones para siguiente fase

- Confirmar documentación y URL base vigentes de SafeTrade, endpoints privados de balances, depósitos, ejecuciones y retiros, esquema de firma y paginación (sin asumir compatibilidad con clientes antiguos).
- Probar credencial real exclusivamente con llamadas GET de lectura y confirmar que carece de acceso a operación/retiro **sin ejecutar operaciones**.
- Ensayar credenciales ausentes/incorrectas, 401/403/429, timeout, duplicados, páginas múltiples y redacción de secretos; pruebas sin red ni credenciales reales.
- Revisar checks y comentarios automáticos del PR antes de merge. No desplegar ni activar recolección por cron hasta contar con pruebas y autorización de despliegue.

## Corte inicial, 11 oct 2026

- SaladCloud emitidos: 133.00 USD; consumidos: 124.37 USD; disponibles: 8.63 USD (capturas de las tres organizaciones).
- Pagos confirmados por recibos Stripe en Gmail: 45.00 USD; transferencias on-chain Solflare: 88 USDT hacia un receptor común, atribución a Salad **todavía pendiente de prueba comercial**.
- Kryptex a dirección `prl1pcc2lcq2jnkzhfk9xnc2nvuv5hzla09ej2ra0g06gw60czv04hxksa090gu`: 96.21820656 PRL pagados, 2.0588357089754936 confirmados pendientes y 7.004125586792953 no confirmados, cifras variables a la fecha de consulta. La dirección `prl1pqmjfltj0lvyzs5lpsm7l3gu8e4sfemt02u3plzyv9gxq7aklc5tspsuqs2` no registró movimientos en PearlTrack durante una consulta; el wallet mostró movimientos adicionales que exigen rastreo para no duplicar.
- SafeTrade: ingresos netos y comisiones aún **sin verificar**; rentabilidad final indeterminada.
