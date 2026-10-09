# HACHE-MINER · aprovisionamiento aislado

El repositorio es **público**. No publicar nombres de cuentas reales, inventarios privados, IPs operativas no necesarias, permisos efectivos, tokens, claves de proveedor ni contraseñas.

## Prerrequisitos
- DNS `miner.hacheinteractive.com` administrado por su propietario y certificado TLS emitido.
- Cuenta administradora legítima para instalación inicial (no se obtienen permisos root desde el usuario normal).
- Host con RAM/CPU suficientes, backups y recuperación ensayada. No afectar aplicaciones existentes.
- Base MariaDB, cuenta SQL, usuario de servicio y directorios **propios**, separados de cualquier otra aplicación.
- Permiso de despliegue por SSH, limitado a `/srv/hache-miner/releases` y a conmutar `/srv/hache-miner/current`; sin sudo general.

## Instalación de infraestructura
1. Validar DNS y recursos sin modificar producción.
2. Crear cuenta de servicio sin shell `hache-miner`, directorios `/srv/hache-miner/releases`, `/var/lib/hache-miner/sessions`, `/etc/hache-miner` y `/var/lib/hache-miner`; asignar permisos restrictivos. El runtime no debe escribir en releases.
3. Crear base MariaDB `hache_miner` y usuario SQL exclusivo con privilegios restringidos a su esquema; importar `database/001_initial.sql` **solo** en esta base.
4. En `/etc/hache-miner/runtime.php` (privado, nunca en Git), utilizar `putenv()` para `MINER_DB_DSN`, `MINER_DB_USER`, `MINER_DB_PASSWORD`, `MINER_MASTER_KEY_FILE` y `MINER_POLL_LOCK`. Usar permisos root:grupo hache-miner 0640. `deploy/load-env.php` es el cargador versionado sin secretos.
5. Ejecutar `php -d auto_prepend_file=/etc/hache-miner/load-env.php bin/bootstrap.php make-key` bajo la identidad del servicio, con la variable de archivo maestro ya configurada, y conservar respaldo cifrado seguro de la clave **fuera del VPS**.
6. Instalar el pool de `deploy/php-fpm-hache-miner.conf.example`, ajustar el servicio existente con una recarga segura **solo después** de `php-fpm8.4 -t`; no sobrescribir pools ajenos. La capacidad máxima del pool es de un proceso.
7. Emitir certificado TLS (p. ej., ACME con webroot) y habilitar el vhost de `deploy/nginx-hache-miner-https.conf.example` tras `nginx -t`. Solo `public/` puede servirse.
8. Aprovisionar el administrador con `php -d auto_prepend_file=/etc/hache-miner/load-env.php bin/bootstrap.php create-admin` desde consola interactiva privada con contraseña única y robusta. Sin contraseña predeterminada.
9. Preparar acceso SSH de deploy y los secretos del Environment `production` en GitHub: `MINER_SSH_HOST`, `MINER_SSH_USER`, `MINER_SSH_KEY`, `MINER_SSH_KNOWN_HOSTS`. Solo entonces activar `MINER_AUTODEPLOY=enabled` después de verificar un release y rollback de prueba.
10. Ejecutar `php -d auto_prepend_file=/etc/hache-miner/load-env.php bin/doctor.php` desde el release y verificar `https://miner.hacheinteractive.com/healthz`; probar inicio/cierre de sesión y vista móvil.
11. Instalar `deploy/systemd/hache-miner-poll.service` y `.timer` tras cargar las claves Salad mediante el panel seguro. Activar timer solo después de dos lecturas de prueba.
12. Vigilar consumo de recursos e integridad de los otros servicios durante la puesta en marcha. Revisar que los costos estimados se distingan de cobros facturados.

## Seguridad y límites
El recolector inicial es **solo lectura**. Ni instala mineros ni modifica réplicas ni consume crédito. La activación requiere privilegios reales legítimos en el host y administración DNS; la autorización por chat no sustituye controles Unix. Jamás pegar secretos en GitHub, logs ni tickets. Las billeteras se almacenan solo como **direcciones públicas**, sin semillas o claves privadas.

## Rollback
Cada release tiene SHA propio. `/srv/hache-miner/current` apunta a un release verificado. Se puede volver al SHA anterior mediante procedimiento autorizado sin editar código en el host. Los cambios SQL requieren comprobar compatibilidad antes de la reversión.
