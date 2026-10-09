# HACHE-MINER · operación

## Estado
Desarrollo. No está en producción. Sin recolección retroactiva inventada.
Se reutilizan patrones Hache-Base: autenticación, auditoría, separación de fuentes y pruebas.

## Infraestructura revisada
VPS Debian 13, Nginx, PHP 8.4, MariaDB; solo 1 GB RAM y poca memoria libre.
No desplegar ni arrancar un collector hasta verificar el impacto en Hache Natación.
Dominio propuesto: miner.hacheinteractive.com (DNS y TLS pendientes).
El usuario deploy-hache no dispone de sudo general; el único ejecutable permitido despliega Hache Natación. Nunca emplearlo para desplegar HACHE-MINER.

## Datos privados fuera del repositorio
Configurar una base MariaDB separada y cuenta de acceso mínimo:
- MINER_DB_DSN
- MINER_DB_USER
- MINER_DB_PASSWORD
- MINER_MASTER_KEY_FILE
- MINER_POLL_LOCK

La clave maestra se crea una única vez, con copia de seguridad recuperable y permisos estrictos. Las credenciales de Salad se configuran cifradas desde el panel, de forma independiente por organización.
No subir claves, frases semilla, tokens o archivos de producción al repositorio público. El módulo de billetera almacena únicamente direcciones PRL públicas. La aplicación no puede retirar fondos.

## Arranque protegido
Ejecutar las migraciones exclusivamente en una base de datos nueva y respaldada.
Crear administrador desde consola privada sin contraseña por defecto:
- php bin/bootstrap.php make-key
- php bin/bootstrap.php create-admin

No regenerar la clave maestra de una instalación existente.

## Recolección
Programar php bin/poll.php cada cinco minutos mediante timer independiente, protegido por un lock. Iniciar la cobertura histórica en el primer polling exitoso. Registrar estados/instancias/logs de Salad en modo lectura. Evitar atribuir hashrate de grupo a una réplica sin identificador fiable.
El gasto estimado necesita dos muestras continuas de la misma instancia, actividad y tarifa comprobada. Cargos reales requieren comprobante de facturación y referencia única.
La API de Kryptex está integrada inicialmente para comprobar conectividad, pero el mapeo de saldos PRL todavía requiere validar respuestas auténticas.

## Autodeploy
El workflow de GitHub quedará preparado para push a main **solo cuando** se configure explícitamente la variable MINER_AUTODEPLOY=enabled.
Se requieren antes: GitHub Environment de producción protegido, credenciales de despliegue limitadas, releases preaprovisionados, DNS y HTTPS, base de datos, administrador, backup y restore probados, healthcheck y revisión de consumo de RAM.
El deploy realiza release por commit y conmutación de symlink para revertir sin editar código manualmente. No modifica la aplicación de natación.
No otorgar privilegios adicionales ni modificar archivos de configuración del VPS sin autorización.

## Criterios para lanzamiento
CI verde, autenticación y CSRF probados, tests de seguridad, ausencia de secretos públicos, vista móvil, protección de webroot, acceso a API validado, dos ciclos de recolección auditados, backups restaurables y rollback documentado.
