# VAST-004 — Receptor SSH de comandos restringidos

La GPU debe tener instalado `deploy/vast-secure-receiver.py` **antes**
de que el VPS solicite la instalación de SRBMiner. El receptor se incorpora a
una imagen/plantilla Vast verificada; no es posible proteger una sesión SSH
arbitraria de root solamente desde el lado del cliente.

## Límite de confianza en la GPU

- Archivo del receptor en `/opt/hache-vast/bin/vast-secure-receiver.py`,
  instalado como **root:root 0700** y proveniente de un commit revisado.
- Política privada `/etc/hache-vast/receiver-policy.json` **root:root 0600**
  con `instance_id`, `machine_id`, `host_id`, `gpu_count`,
  `ssh_host`, `ssh_port`, `release_commit`,
  `release_sha256` y `files` (SHA Git de los cuatro archivos del bundle).
  La política procede de datos verificados de Vast y GitHub. No aceptar
  valores introducidos por un usuario sin autorización.
- Clave **nueva y exclusiva para aprovisionar** en authorized_keys de root.
  Debe tener una línea de este formato; `PUBLIC_KEY_FROM_TRUSTED_VPS`
  es un marcador y no una clave real:

```text
restrict,no-pty,command="/usr/bin/python3 /opt/hache-vast/bin/vast-secure-receiver.py" ssh-ed25519 PUBLIC_KEY_FROM_TRUSTED_VPS
```

La clave no puede permitir shell arbitraria, agent forwarding, X11, túneles,
puertos o pty. **Revocar/retirar las claves de root irrestrictas que pudieran
permitir al mismo agente saltarse la política**; no confiar en `-o`
del cliente como control del lado servidor. Probar antes de abrir producción
que `ssh ... 'date'` falla; solamente los verbos exactos son aceptados.

El receptor usa `SSH_ORIGINAL_COMMAND` y admite exclusivamente:

- `stage INSTANCE_ID COMMIT40`: consume tar por stdin, coteja SHA-256 y
  cada blob Git con la política, publica una carpeta privada atómica e
  idempotente.
- `configure INSTANCE_ID COMMIT40`: consume JSON privado por stdin con
  `recipe` y `ingest_key`, comprueba identidad, wallet y pool permitidos;
  almacena los tres archivos con modo 0600 sin imprimir secretos.
- `apply INSTANCE_ID COMMIT40`: comprueba nuevamente los hashes y ejecuta
  el instalador, que **nunca crea /root/onstart.sh ni arranca SRBMiner**.

No existen órdenes para comprar, arrancar, detener, eliminar, cambiar la
billetera libremente ni ejecutar shell. La configuración de ingreso debe
corresponder al secreto reconocido por el receptor HMAC del VPS; si no existe
una clave por instancia en el backend, no declarar telemetría operativa.
La configuración queda solo en la GPU; jamás en los PR, logs o CLI.

## Requisitos financieros y de identidad

Cada solicitud viene del `hache-miner` protegido (no de `gpt-agent`)
con un plan root de un uso, proveedor activo y coincidencia de máquina,
GPU y SSH. El proceso del VPS consulta una **lease financiera activa**
con heartbeat reciente antes de permitir conectar por SSH. La protección
financiera es estimada y requiere comprobar la facturación real de Vast.

Para validar el receptor sin GPU:

```sh
python3 tests/vast-secure-receiver-regression.py
```

El funcionamiento con una GPU real y shares aceptados requiere prueba
controlada, alquiler autorizado y presupuesto. Hasta entonces, VAST-004
es una implementación probada de forma aislada, no una automatización
integral certificada.

## Firmas HMAC separadas por instancia

El colector nuevo obtiene `ingest.key` (cadena hexadecimal de 64 caracteres)
de la derivación administrativa:
`hash_hmac('sha256', 'vast-instance-v1:' . INSTANCE_ID, MINER_VAST_INGEST_KEY)`.
El secreto maestro **nunca viaja a Vast**. La configuración del colector
lleva `key_scheme=instance-v1` y envía
`X-Hache-Key-Version: instance-v1`, que hace al servidor elegir solo
la clave derivada correspondiente al ID autenticado.

Los colectores anteriores, sin dicha cabecera, se siguen admitiendo
por compatibilidad; debe planificarse su migración para poder retirar
posteriormente la aceptación de la clave global compartida. Nunca usar
la clave maestra en los archivos `*.private.json` de nuevas GPUs.
