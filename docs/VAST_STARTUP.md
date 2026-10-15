# Vast.ai PRL 5090x2 — arranque automático seguro

Este hook sirve solo para la instancia Vast con SRBMiner instalado directamente.
No altera la imagen Docker ni las organizaciones de SaladCloud.

## Prerrequisitos

- Modo Vast SSH: Vast ejecuta /root/onstart.sh al arrancar el contenedor.
- Minero /opt/prl-srbminer/SRBMiner-MULTI instalado y ejecutable.
- Lanzador /root/start-prl-vast-5090x2.sh ya validado con su wallet.worker,
  configuración privada y Kryptex TLS.
- flock disponible en el host.

## Instalación controlada sin interrumpir minería

1. Verificar instancia y proceso, sin detener ni reiniciar las GPU.
2. Descargar deploy/vast-onstart.sh desde el SHA de un commit fusionado en main.
   Validar con bash -n; no descargar desde ramas móviles.
3. Respaldar /root/onstart.sh en privado antes de reemplazarlo.
4. Instalar hook con modo 0700, usando movimiento atómico.
5. Ejecutar bash /root/onstart.sh con SRBMiner ya corriendo:
   debe indicar que el minero ya está activo y dejar su PID intacto.
6. Confirmar en /var/log/prl-miner.log que siguen llegando shares.

El hook no almacena credenciales ni wallets. Usa el lanzador existente,
anexa logs y tiene protección contra arranques simultáneos.

Fuentes Vast:
https://github.com/vast-ai/docs/blob/main/guides/reference/faq/instances.mdx

## Límites y recuperación

- Esta etapa cubre el arranque al iniciar la instancia, NO recuperación automática
  ante caídas de proceso mientras la instancia permanezca encendida.
- Para recuperarse de caídas de proceso hará falta un supervisor separado,
  con política de reinicios y límites de seguridad verificados.
- Restaurar el respaldo de /root/onstart.sh revierte únicamente este cambio.
- No usar reboot/stop/start sobre una GPU activa para demostrar funcionamiento.
- Esta etapa no instala ni activa por sí sola deploy/vast-collector.py:
  la telemetría HACHE-MINER continúa siendo un paso separado.
