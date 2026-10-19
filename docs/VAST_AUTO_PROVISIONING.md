# VAST-003 — instalación de SRBMiner y alta automatizada, sin contratación implícita

Esta fase **no contrata ni inicia** instancias en Vast. Se utiliza solo después
de recibir aprobación expresa para una instancia, tarifa, duración y presupuesto
mediante el mecanismo de Vast ya existente.

## 2. Preparación e instalación del minero

Nueva herramienta del mismo repositorio: `deploy/vast-auto-prepare.py`, que
reutiliza **sin duplicar** `vast-instance-prepare.py`,
`vast-onstart.sh` y `vast-collector.py`.

En la **instancia Vast** (no en el VPS), disponer del paquete fijado por
commit de GitHub y de un archivo privado root:root 0600:
`/etc/hache-vast/approved-recipe.json`. Los campos obligatorios son:

```json
{
 "instance_id": "123456",
 "machine_id": "12345",
 "host_id": "23456",
 "gpu_count": 2,
 "hourly_usd": "0.200000",
 "wallet": "prl1REEMPLAZAR_CON_DIRECCION_REAL_APROBADA",
 "pool_host": "prl.kryptex.network",
 "pool_port": 8048,
 "miner_version": "3.7.6",
 "release_url": "https://github.com/doktor83/SRBMiner-Multi/releases/download/3.7.6/SRBMiner-Multi-3-7-6-Linux.tar.gz",
 "archive_sha256": "SHA256_HEX_REAL_DEL_ARCHIVO_APROBADO",
 "miner_sha256": "SHA256_HEX_REAL_DEL_BINARIO_EXTRAIDO"
}
```

**Es una plantilla ilustrativa, no se puede ejecutar:** no fija realmente ni
wallet ni hashes; NO debe copiarse a producción sin comprobar el artefacto y
validar expresamente el destino PRL. El instalador **no descarga una versión
'latest' dinámica** ni acepta URL ajena al repositorio oficial. Exige SHA-256
del archivo comprimido y del ejecutable.

```sh
# Preflight de la receta: sin escrituras ni descargas
python3 deploy/vast-auto-prepare.py --recipe /etc/hache-vast/approved-recipe.json

# Aplicar solo en GPU que esté ejecutándose bajo alquiler autorizado
python3 deploy/vast-auto-prepare.py --recipe /etc/hache-vast/approved-recipe.json \
    --apply --confirm-instance 123456

# Encender el minero es una operación SEPARADA, no admitida por
# vast-auto-prepare.py. Se implementará mediante aprobación independiente,
# siempre dentro del alquiler y del supervisor financiero autorizados.
```

El instalador genera el lanzador para `pearlhash`, `--tls true`,
`--disable-cpu`, billetera y worker único. Comprueba GPU físicas, revisa SHA,
rechaza conflictos y NO instala ni habilita `/root/onstart.sh` durante `--apply`.
Si ese hook ya existe, la preparación se detiene: no se considera inofensivo
un arranque automático preexistente.

**Prerequisito que sigue requiriendo un canal autorizado:** la clave HMAC
del receptor debe existir en la GPU en `/etc/hache-vast/ingest.key`, root
0600. No se genera, publica ni copia automáticamente desde un usuario sin
acceso al secreto. Tampoco se apaga ni se compra GPU durante la preparación
de GitHub.

## 3. Alta automatizada en el monitor

La función privada `app/vast-enrollment.php` toma la proyección verificada
del proveedor: `instance_id`, `machine_id`, `host_id`, GPU físicas,
precio horario, estado activo y SSH real. Rechaza identidades inestables,
GPU incompletas y alteraciones de una inscripción existente.

La acción acotada `vast.register` de Hache-Base (cuando esté desplegada)
reutiliza el intermediario de GPT Agent con autorización root de un solo uso;
el agente no obtiene acceso SQL general ni secretos. Inserta exclusivamente
una instancia nueva en `vast_instances`, `enabled=1`, con umbrales
deshabilitados (`low_ths=normal_ths=0`) hasta prueba real. No modifica datos
de Salad ni sobrescribe filas existentes conflictivas. Se ejecuta tras
preparar/arrancar la GPU autorizada para que empiece a recibir muestras firmadas.

El watchdog `hache-miner-vast-watchdog.timer` **ya instalado en el VPS**
detecta automáticamente instancias `enabled=1`. No crear otro temporizador.
Una fila nueva entra en gracia durante ~180 s; si la telemetría no llega, el
watchdog debe alertar y **NO** se considerará producción verificada.

## Comprobación obligatoria de producción

Tras la instalación, revisar:
- Coincidencia del binario, CLI, wallet.worker y pool TLS.
- GPU reales mediante `nvidia-smi` y proceso SRBMiner único.
- Muestras HMAC frescas por cada GPU y dos ciclos del watchdog.
- Shares nuevos aceptados por Kryptex, no lecturas históricas del pool.
- Costo efectivo y duración del alquiler frente al presupuesto autorizado.
- Notificación de prueba recibida en Android.

Sin todos estos puntos, el resultado es **NO LISTA** o
**MINANDO SIN ALERTAS**. El plan de arranque de Vast requiere una aprobación
distinta para cualquier gasto. Si VPS, API o parada automática fallan, la tarifa
puede continuar devengándose; no se promete un tope garantizado.

## Pendiente para orquestación totalmente desatendida

La capa de comandos SSH firmemente vinculada a cada instancia debe preparar
y transferir el paquete de commit, wallet aprobada y clave HMAC sin exponerlos
en consola. Su identidad SSH debe validarse en vivo. La vía de aprobación
de operaciones desde ChatGPT sin ConnectBot todavía no está finalizada.
No presentar VAST-003 como end-to-end sin probarlo en una instancia autorizada.

## Vinculación del paquete al repositorio GitHub

Al transferir archivos de esta herramienta, el cliente de GPT Agent
`vast_provision.py` verifica **cada Git blob SHA** contra un manifiesto
administrativo root de un solo propósito. El manifiesto vincula commit,
instancia, identidad física, relay SSH, acción `stage` **o** `apply`,
y vigencia máxima de 15 minutos. No se acepta que el agente establezca
por sí mismo los hashes de confianza. Se comprueba de nuevo el contenido
de los archivos en la GPU antes de ejecutarlos.

La autorización del paquete no autoriza `--start`: esa opción se
eliminó por completo del instalador. Los metadatos de aprobación pueden
ser legibles por `gpt-agent`, pero siempre serán propiedad de root,
sin permisos de escritura para grupos u otros usuarios. No incorporan
claves privadas, HMAC ni credenciales de Kryptex.

## Correcciones Codex y protección de instancia (P1)

Antes de permitir `--apply`, se necesita el archivo privado
`/etc/hache-vast/host-binding.json`, creado **exclusivamente desde el
transporte SSH de confianza del VPS**, después de comparar el perfil con la
API actual de Vast y su huella de servidor. Debe tener ID de instancia,
`machine_id`, `host_id`, GPU, `ssh_host` y `ssh_port`; la receta se
compara con todos esos datos. No basta con que un operador repita el ID
como argumento ni con que `nvidia-smi` reporte ocho GPU.

El alta inicial del host ocurre durante `stage` usando la conexión SSH
ya verificada, la aprobación administrativa del commit y la GPU específica.
El acceso root por otros canales no debe considerarse una vía aprobada de
instalación. El ejecutor seguro de la pasarela debe consumir un permiso
de un solo uso antes de contactar la GPU; el envío directo desde
`gpt-agent` queda bloqueado hasta instalar ese ejecutor.

La instalación no instala `/root/onstart.sh` y rechaza un hook existente;
el watchdog/arranque del minero se activarán después mediante una
autorización separada, nunca como efecto secundario de preparar archivos.
