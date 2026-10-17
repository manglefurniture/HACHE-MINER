# VAST-001 — preparación por instancia (sin alquiler ni arranque)

Este procedimiento **no opera en el VPS** ni toca SaladCloud. El programa
`deploy/vast-instance-prepare.py` prepara una instancia Vast **ya identificada
y autorizada**, usando archivos de un checkout de GitHub fijado por commit.

## Qué automatiza

- Validación de que el identificador aprobado y el número de GPU físicas son
  compatibles con el manifiesto local (1–16 GPU), vía `nvidia-smi -L`.
- Validación del hash SHA-256 de un SRBMiner ya instalado o de un archivo
  descargado/validado **previamente por el operador**. Si no está instalado,
  `--apply` instala una copia comprobada, sin descargar nada.
- Comprobación de que el lanzador privado contiene `wallet.vast-<instance_id>`,
  usa `pearlhash` y desactiva CPU. **No sustituye la comprobación manual del
  comando, pool TLS, wallet efectiva y shares reales**.
- Preparación del hook idempotente de arranque y su configuración por instancia,
  del colector por GPU y de un cron con `flock` cada minuto. La clave HMAC se
  **lee desde un archivo root-owned 0600**, no desde argumentos, Git ni crontab.
- Negativa a sobrescribir configuraciones distintas y a preparar una instancia
  que ya tenga ese proceso minero corriendo.

El modo por defecto, `--check`, **no escribe nada**. El modo `--apply`
es una acción futura sobre el host **solo con autorización para esa instancia**;
no inicia ni reinicia la GPU y no consulta API de Vast, pero instala el cron
de telemetría, que puede empezar a ejecutarse en un cron activo.

## Requisitos antes de ejecutarlo

1. Autorización específica para la instancia y su duración, presupuesto y
   destino de minería. Inventario fiable desde Vast: `instance_id`,
   `machine_id`, `host_id`, tarifa total por hora, GPU y driver.
2. Checkout/paquete de `deploy/vast-instance-prepare.py`,
   `deploy/vast-onstart.sh`, `deploy/vast-collector.py` **del mismo commit
   ya fusionado en main**; comprobar su procedencia. Nunca descargar desde una
   rama mutable para instalar en producción.
3. Binario del minero o paquete extraído mediante canal confiable, versión
   aprobada y SHA-256 **verificado**. El script no inventa versión ni hash.
4. Lanzador privado de esa instancia, root-owned, permisos 0700, wallet PRL
   pública aprobada y worker `vast-<instance_id>`, SRBMiner PearlHash con
   TLS al pool Kryptex, `--disable-cpu`. Sin reutilizar lanzadores de pruebas.
5. Clave HMAC preexistente transmitida por SSH autenticado y colocada en
   `/etc/hache-vast/ingest.key` con permisos 0600. Nunca imprimirla ni
   añadirla a Git. `python3`, `flock`, `cron`, `nvidia-smi` disponibles.

## Manifiesto privado por host

Crear `/etc/hache-vast/instance.json` como archivo regular de **root** modo
`0600`. Este ejemplo contiene **valores ilustrativos, no utilizables**:

```json
{
  "instance_id": "123456",
  "machine_id": "host-machine-id",
  "host_id": "host-id",
  "gpu_count": 2,
  "hourly_usd": "0.15",
  "miner_path": "/opt/prl-srbminer/SRBMiner-MULTI",
  "miner_source": "/root/staging/SRBMiner-MULTI",
  "miner_sha256": "64_HEX_DIGITS_FROM_APPROVED_RELEASE",
  "launcher_path": "/root/start-prl-vast.sh",
  "wallet_worker": "APPROVED_PRL_WALLET.vast-123456",
  "miner_log": "/var/log/prl-miner.log",
  "ingest_key_file": "/etc/hache-vast/ingest.key"
}
```

Si el binario ya está instalado y coincide con el SHA-256 aprobado, omitir
`miner_source`. El manifiesto es **privado** porque contiene la wallet y
las identidades operativas. Nunca versionarlo, copiarlo en tickets públicos
ni enviarlo al chat.

## Ejecución en una instancia explícitamente autorizada

Desde la carpeta `deploy/` de un commit aprobado:

```bash
sudo python3 vast-instance-prepare.py \
  --manifest /etc/hache-vast/instance.json

# Solo tras revisar la salida y obtener autorización de aplicar EN ESTE HOST:
sudo python3 vast-instance-prepare.py \
  --manifest /etc/hache-vast/instance.json \
  --apply --confirm-instance 123456
```

El instalador deja `/root/onstart.sh`, `/etc/hache-vast/onstart.conf`,
`/etc/hache-vast/collector.json`, `/opt/hache-vast/vast-collector.py`
y `/etc/cron.d/hache-vast-collector`. Si hay un archivo previo diferente,
**se detiene** para respaldar y revisar manualmente, sin forzar el reemplazo.
Solo se copia/instala el minero si el destino está ausente y el SHA es correcto.

El hook de arranque **no se ejecuta al instalarlo**; entrará en acción en un
futuro arranque legítimo de la instancia. No usar reboot como prueba.
El colector tiene compatibilidad con su modo original por variables de entorno,
pero el cron nuevo usa exclusivamente un JSON privado y una clave por archivo.
No realiza cambios en la base de datos ni habilita por sí solo VAST-002.

## Verificación y alta real pendientes

- Verificar pool TLS, destino efectivo de pagos, worker único, proceso único,
  shares nuevos y TH/s por GPU; comprobar que se corresponde con la instancia.
- Registrar identidad real, tarifa y GPU en `vast_instances` (sin datos
  inventados) y verificar dos muestras firmadas y frescas en el servidor,
  `cron` activo, receptores y web.
- Comprobar `hache-miner-vast-watchdog.timer`, cobertura de esta instancia,
  dos ciclos y aviso de **PRUEBA** efectivamente recibido en Android.
- Sin estas verificaciones, estado obligatorio: **NO LISTA** o
  **MINANDO SIN ALERTAS**, nunca "protegida".

**Límites:** no aprovisiona instancias Vast mediante API, no obtiene billeteras
ni claves, no instala drivers ni dependencias de CUDA, no inicia SRBMiner al
aplicar, no crea registros en el VPS y no promete autorreinicio ante caída.
El coste de una instancia ya alquilada puede continuar aunque no esté minando.
Consultar también `docs/VAST_INSTANCE_ONBOARDING_RULE.md` y
`docs/VAST_WATCHDOG_ALERTS.md`.

Para la revisión local desde Windows y el procedimiento permitido tras un
bloqueo de ejecución remota, consultar `docs/VAST_WINDOWS_OPERATOR_RUNBOOK.md`.
