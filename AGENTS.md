# AGENTS.md — reglas operativas de HACHE-MINER

## VAST-001: alta obligatoria de toda nueva instancia Vast.ai

Cuando una tarea consista en **buscar, evaluar, alquilar, probar, configurar, reanudar o poner a minar** una instancia de Vast.ai para Pearl (PRL), seguir **`docs/VAST_INSTANCE_ONBOARDING_RULE.md`** de principio a fin. La regla aplica a cualquier GPU NVIDIA y a 1–16 GPU por instancia; nunca asumir que todas son RTX 5090 ni que tienen dos GPU.

### Puertas de seguridad (sin excepciones)

1. **No alquilar, crear, destruir, reiniciar ni ampliar instancias que generen cargos** sin autorización expresa para esa acción y presupuesto/ventana concretos. La búsqueda y evaluación pueden ser de solo lectura.
2. Antes de arrancar SRBMiner, obtener una **billetera pública PRL aprobada por el usuario** y verificar que coincide exactamente con la del lanzador. El nombre de worker debe ser **único por `instance_id`**, sin usar billeteras ni worker IDs heredados de pruebas. Si hay discrepancia: **no minar**.
3. Identificar la instancia real (`instance_id`, `machine_id`, `host_id`), precio USD/h real, GPU físicas mediante `nvidia-smi -L`, conectividad, límites de potencia y versión/hash del binario del minero. No reutilizar métricas de otra instancia como actuales.
4. El minero debe usar el binario y algoritmo **probados y validados** para la arquitectura GPU, `pearlhash`, Kryptex por TLS y CPU desactivada. **Nunca descargar un binario sin verificación de integridad y procedencia.**
5. Implementar arranque al inicio de la instancia **idempotente** (`flock`, comprobar proceso existente, no duplicarlo) y colector por minuto. Guardar la clave HMAC **fuera de Git** con permisos `0600`; transferir por canal autenticado sin mostrarla en chat, logs o argumentos.
6. Registrar en `vast_instances` la identidad y tarifa reales, y verificar al menos dos muestras cronológicas de cada GPU en `vast_samples`/`vast_gpu_samples`; la API recibe en `https://miner.hacheinteractive.com/vast-ingest` (sin sufijo `.php`).
7. **VAST-002 — vigilancia y ntfy obligatorios en cada alta:** consultar `docs/VAST_WATCHDOG_ALERTS.md` y seguir la sección E de VAST-001. Ya existe watchdog **externo en VPS** (`hache-miner-vast-watchdog.timer`, cada minuto) que usa el **mismo ntfy de Salad**, sin alterar su monitor. Exigir que cada instancia habilitada aparezca en la cobertura, con dos ciclos correctos y notificación de prueba recibida en Android. Detecta telemetría perdida (>180 s), GPU a 0 TH/s en dos lecturas, estancamiento prolongado de shares y recuperación. **Un heartbeat independiente para distinguir causa exacta de la caída y el reinicio automático aún NO están implementados.** Sin cobertura o prueba real: `MINANDO SIN ALERTAS`, nunca anunciar protección.
8. No declarar la puesta en marcha «completada» hasta comprobar **minero activo, dirección correcta, shares aceptados, hashrate por GPU, costo/h, cron operativo y muestras recientes en el panel**. Un proceso vivo o un HTTP de prueba no prueban por sí solos que haya minería rentable.
9. **Aislamiento:** no modificar SaladCloud, sus organizaciones, sus umbrales de autorrelocación ni otros mineros como efecto colateral de configurar Vast.
10. **Control de cambios:** crear cambios persistentes mediante rama, tests, PR, revisión y despliegue autorizado. No insertar secretos, identidades privadas o datos operativos sensibles en GitHub. No probar reiniciando una instancia que esté minando.
11. Ante discrepancias, datos viejos, pool sin shares, envío HMAC fallido o costo no justificable: marcar la instancia como **no lista** y pedir una decisión; no silenciar fallas ni inventar disponibilidad, rentabilidad o lecturas.

### Alcance y límites

Esta es una **regla de aprovisionamiento para agentes/operadores** y no un disparador que por sí mismo descubra o alquile instancias. El procedimiento se ejecuta cuando se trabaja explícitamente con una candidata Vast autorizada. Para alquiler autónomo o despliegue totalmente desatendido se requiere implementar y validar un orquestador independiente con permisos y controles financieros específicos.

Otros cambios del repositorio que no traten instancias Vast quedan fuera de esta regla.

## GPT-ACCESS-001 — entrada operativa para diagnóstico HACHE-MINER

Cuando se solicite investigar o corregir HACHE-MINER, **comprobar accesos conectados
antes de declarar falta de conexión**. No confundir acceso a herramientas con
autonomía de un modelo residente en el VPS.

1. **GitHub es la fuente de verdad**: inspeccionar repo, commit, PR y CI; cambios
   persistentes siempre rama → pruebas → PR → revisión/checks → merge → deploy
   versionado → comprobación. No modificar código de la aplicación directamente
   en el VPS ni en las GPU.
2. **VPS**: preferir Desktop Commander conectado a `hache-natacion` bajo la
   identidad `gpt-agent` para diagnóstico, consultas y operación limitada; el
   segundo dispositivo `deploy-hache` solo para despliegues y verificación
   permitidos. Descubrir los IDs de dispositivo actuales, no asumir que persisten.
3. **Puerta local**: tras desplegar la extensión de Hache-Base
   `extensions/mining-ops/access_gateway.py`, usar:
   `python3 /home/gpt-agent/mining-ops/current/access_gateway.py status`,
   `query vast.list`, `query salad.targets`, `query telemetry`.
   Este comando reutiliza el broker privado HACHE-MINER ya existente. Si el
   release no tiene `access_gateway.py`, usar temporalmente el cliente
   `/home/gpt-agent/mining-operator/client.py` y `mining_status.py`;
   registrar cuál vía se verificó de verdad.
4. **GPU Vast/Salad**: revisar primero proveedor/ID/estado running, luego SSH.
   Salad publica host/puerto de la instancia en el Portal; Vast ofrece su
   `ssh-url`. La laptop `Hache` dispone de cliente SSH y varias claves
   históricas, pero eso NO prueba que estén autorizadas para la GPU actual.
   Verificar huella de host y clave autorizada por instancia; no copiar claves
   privadas ni desactivar `StrictHostKeyChecking`.
5. **Pruebas y sincronización**: los comandos de solo lectura se pueden
   ejecutar con permiso existente. Reparaciones, arranques, cambios de
   configuración, despliegues en GPU y acciones facturables requieren intención
   explícita, identidad/objetivo validados y autorización aplicable. Para Vast,
   seguir también VAST-001 y VAST-002.
6. **Seguridad**: sin sudo general, shell remoto irrestricto desde el broker,
   cambios de privilegios SQL ni préstamos de secretos. La puerta de
   diagnóstico no equivale a SQLGuard: su transición de privilegios sigue
   siendo un trabajo independiente. Nunca usar telemetría vieja como
   prueba de minería activa.

Guía técnica y límites: `manglefurniture/Hache-Base`,
`extensions/mining-ops/ACCESS_GATEWAY.md`. No duplicar los timers ni los
controladores existentes de Vast y Salad.

## VAST-003 — instalación y alta automatizables

Para cualquier GPU Vast de alquiler **ya expresamente autorizado**, usar la
secuencia de `docs/VAST_AUTO_PROVISIONING.md`. No inventar el valor de la
billetera, la URL, la versión ni las sumas SHA; deben estar explícitamente
aprobadas. La preparación se realiza en el host con
`deploy/vast-auto-prepare.py`, y aprovecha el instalador VAST-001 existente.
El cliente SSH acotado de GPT Agent, en Hache-Base
`extensions/mining-ops/vast_provision.py`, solo transfiere paquetes verificados
y aplica la instalación para la instancia concreta una vez que Vast confirme
su identidad y el usuario haya autorizado la acción.

Para darla de alta en el VPS usar `vast.register`, acción del intermediario
Hache-Base bajo **grant root de un solo uso** y validación de identidad en vivo,
que delega en `app/vast-enrollment.php`; no usar SQL genérico ni editar una
instancia existente conflictiva. El registro se hace con la GPU activa y
conlleva `enabled=1` en el watchdog ya instalado. Exigir luego dos muestras
frescas, nuevas shares aceptadas y prueba de ntfy recibida.

El canal seguro para colocar receta privada/HMAC en una GPU recién alquilada
y el circuito completo de aprobación desde ChatGPT **siguen pendientes**:
no marcar el pipeline como autónomo end-to-end hasta probarlos. No contratar,
arrancar ni apagar GPU a partir de estas instrucciones sin luz verde y
presupuesto específicos.
