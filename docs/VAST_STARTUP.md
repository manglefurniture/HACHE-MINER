# Vast.ai PRL — arranque automático seguro

Este hook arranca un minero existente al iniciar una instancia Vast SSH. No
descarga binarios, cambia GPU, ni altera la imagen Docker o SaladCloud. Sus
defaults mantienen compatibilidad con la instalación de referencia 5090x2;
otras instancias usan configuración local privada, sin editar el hook común.

## Prerrequisitos

- Modo Vast SSH: Vast ejecuta `/root/onstart.sh` al arrancar el contenedor.
- Minero existente e instalado, más un lanzador ya validado para esa instancia,
  con la wallet.worker aprobada y configuración privada/Kryptex TLS.
- `flock` disponible en el host.

## Configuración específica de cada instancia

Los valores por defecto son `/root/start-prl-vast-5090x2.sh`,
`/opt/prl-srbminer/SRBMiner-MULTI` y `/var/log/prl-miner.log`. Para otra
instancia, crear `/etc/hache-vast/onstart.conf` como archivo regular propiedad
de root y modo `0600`. El formato permite solo estas rutas absolutas:

```text
VAST_LAUNCHER=/root/start-prl-vast.sh
VAST_MINER=/opt/prl-srbminer/SRBMiner-MULTI
VAST_MINER_LOG=/var/log/prl-miner.log
VAST_ONSTART_LOCK=/run/lock/hache-prl-vast-onstart.lock
```

Se pueden omitir claves para conservar sus defaults. No agregar credenciales,
wallets, IDs de instancia, regiones o tarifas. El hook rechaza symlinks,
archivos no root-owned, permisos de grupo/otros, claves desconocidas y rutas
relativas. La configuración no ejecuta contenido como shell.

## Instalación controlada sin interrumpir minería

1. Verificar instancia y proceso, sin detener ni reiniciar las GPU.
2. Descargar `deploy/vast-onstart.sh` desde el SHA de un commit fusionado en
   `main`. Validar con `bash -n`; no descargar desde ramas móviles.
3. Respaldar `/root/onstart.sh` en privado antes de reemplazarlo.
4. Instalar hook con modo `0700`, usando movimiento atómico.
5. Ejecutar `bash /root/onstart.sh` con el minero ya corriendo: debe indicar que
   está activo y dejar su PID intacto.
6. Confirmar en el log configurado que siguen llegando shares.

El hook no almacena credenciales ni wallets. Usa el lanzador configurado,
anexa logs y tiene protección contra arranques simultáneos. El nombre del
proceso se deriva de la ruta del binario configurada para detectar duplicados.
Para evitar coincidencias ambiguas de `pgrep -x`, el nombre del ejecutable debe
tener de 1 a 15 caracteres ASCII alfanuméricos, guion o guion bajo; otras
variantes se rechazan antes de iniciar el minero.

Fuentes Vast:
https://github.com/vast-ai/docs/blob/main/guides/reference/faq/instances.mdx

Para preparar archivos y el colector por instancia de forma controlada, consultar
`docs/VAST_PREPARATION.md`. Su modo `--apply` requiere autorización específica;
no constituye permiso de alquiler ni de arranque del minero.

## Límites y recuperación

- Esta etapa cubre el arranque al iniciar la instancia, NO recuperación automática
  ante caídas de proceso mientras la instancia permanezca encendida.
- Para recuperarse de caídas de proceso hará falta un supervisor separado,
  con política de reinicios y límites de seguridad verificados.
- Restaurar el respaldo de `/root/onstart.sh` revierte únicamente este cambio.
- No usar reboot/stop/start sobre una GPU activa para demostrar funcionamiento.
- Esta etapa no instala ni activa por sí sola `deploy/vast-collector.py`:
  la telemetría HACHE-MINER continúa siendo un paso separado.
