# HACHE-MINER · producción aislada (octubre 2026)

## Comprobaciones reales al 9 de octubre de 2026
- Droplet **hache-natacion**: 1 vCPU, 1 GB RAM, 1 GB swap; memoria disponible ~196 MB; 13 GB libres en disco.
- Cuenta remota `deploy-hache`: sudo únicamente para `/usr/local/sbin/deploy-hache-natacion`; **NO** usarlo para HACHE-MINER.
- MariaDB por defecto conectado como `deploy_hache_ops`, con permisos únicamente sobre `hache_natacion`; **NO** reutilizar esa base ni usuario.
- No existe `/srv/hache-miner`. `miner.hacheinteractive.com` aún no resuelve; hay otros vhosts en Nginx.
- GitHub Actions no puede configurar sus propios secrets de repositorio desde este conector. No activar `MINER_AUTODEPLOY` prematuramente.

## Aprovisionamiento requerido (root / administrador legítimo)
1. Configurar DNS A de `miner.hacheinteractive.com` a `162.243.32.80`. Confirmar propagación.
2. Revisar backups, CPU, memoria, procesos PHP y servicios existentes antes de crear nada. Si memoria cae por debajo de un umbral seguro, ampliar capacidad **solo con autorización de gasto** o separar VPS.
3. Crear una cuenta de servicio `hache-miner` sin shell y directorios separados `/srv/hache-miner/releases`, `/var/lib/hache-miner/sessions`, `/etc/hache-miner`. Solo la cuenta de despliegue podrá modificar releases. El servicio PHP y el recolector **no** tendrán permiso para modificar código.
4. Crear base y cuenta MariaDB exclusivas para HACHE-MINER, con privilegios solo de su esquema; importar `database/001_initial.sql`. No tocar `hache_natacion`.
5. Crear la clave maestra mediante `php bin/bootstrap.php make-key` con `MINER_MASTER_KEY_FILE` apuntando a un archivo **nuevo** fuera del árbol web, propiedad de la cuenta de servicio y modo 0600. Guardar respaldo recuperable cifrado fuera del VPS.
6. Crear `/etc/hache-miner/runtime.php` fuera de GitHub; exclusivamente `putenv()` para las variables `MINER_DB_DSN`, `MINER_DB_USER`, `MINER_DB_PASSWORD`, `MINER_MASTER_KEY_FILE` y `MINER_POLL_LOCK`, con permisos root:grupo hache-miner 0640. No copiar secretos en issues, logs, acciones de GitHub ni vhosts.
7. Instalar `deploy/load-env.php` en `/etc/hache-miner/load-env.php` sin modificarlo; instalar el pool de PHP-FPM del ejemplo. Validar con `php-fpm8.4 -t` antes de **recargar** PHP-FPM.
8. Configurar webroot temporal ACME y emitir certificado con Certbot después de verificar DNS. Instalar vhost del ejemplo bajo nombre propio; `nginx -t` antes de recargar. La aplicación solo expone `public/`.
9. Crear administrador con contraseña interactiva única >=16 caracteres: `php -d auto_prepend_file=/etc/hache-miner/load-env.php bin/bootstrap.php create-admin`. No usar credenciales por defecto.
10. Preparar acceso SSH de despliegue para una cuenta de alcance exclusivo a `/srv/hache-miner/releases` y `/srv/hache-miner/current`; host key verificada, sin sudo general. Establecer en GitHub Environment `production` los secretos `MINER_SSH_HOST`, `MINER_SSH_USER`, `MINER_SSH_KEY`, `MINER_SSH_KNOWN_HOSTS` y la variable de repo `MINER_AUTODEPLOY=enabled` **solo después** de validar manualmente el flujo y el rollback.
11. Ejecutar `php -d auto_prepend_file=/etc/hache-miner/load-env.php bin/doctor.php` desde el directorio release, `curl -fsS https://miner.hacheinteractive.com/healthz`, y prueba de login privado. Instalar servicio y timer de `deploy/systemd`; `systemctl daemon-reload`, habilitar timer **después** de cargar credenciales legítimas de Salad desde el panel.
12. Verificar dos sondeos, logs minimizados, fallos, uso de CPU/RAM, alarmas y que Hache Natación continúe respondiendo. Mantener acciones automáticas de alquiler, redistribución o apagado desactivadas hasta disponer de datos fiables.

## Límites de acceso de este entorno
La autorización conversacional **no cambia los permisos Unix**, no otorga capacidad de edición DNS, ni permite crear secretos en GitHub mediante el conector actual. No intentar evadir estas restricciones. Nunca copiar archivos de configuración versionados a mano para modificar la aplicación: código → GitHub → PR → CI → merge → despliegue.

## Rollback
La versión desplegada es el enlace `/srv/hache-miner/current` a un directorio immutable por SHA de Git. Conservar releases anteriores. La vuelta a una versión previa se ejecuta mediante el procedimiento autorizado de activación; nunca desplegar código modificado solo en el servidor. Las migraciones son forward-only, así que requieren evaluación de compatibilidad antes de volver atrás.
