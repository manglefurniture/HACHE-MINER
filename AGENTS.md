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
7. **VAST-002 — vigilancia obligatoria:** consultar `docs/VAST_WATCHDOG_ALERTS.md`. El reporte ordinario se detiene si SRBMiner muere: exigir heartbeat independiente, vigilancia desde VPS y avisos reales al operador (minero caído, GPU a cero, shares detenidos, telemetría obsoleta), con deduplicación y recuperación. **La función de alertas todavía requiere implementación y verificación; jamás presentarla como operativa solo por esta regla.**
8. No declarar la puesta en marcha «completada» hasta comprobar **minero activo, dirección correcta, shares aceptados, hashrate por GPU, costo/h, cron operativo y muestras recientes en el panel**. Un proceso vivo o un HTTP de prueba no prueban por sí solos que haya minería rentable.
9. **Aislamiento:** no modificar SaladCloud, sus organizaciones, sus umbrales de autorrelocación ni otros mineros como efecto colateral de configurar Vast.
10. **Control de cambios:** crear cambios persistentes mediante rama, tests, PR, revisión y despliegue autorizado. No insertar secretos, identidades privadas o datos operativos sensibles en GitHub. No probar reiniciando una instancia que esté minando.
11. Ante discrepancias, datos viejos, pool sin shares, envío HMAC fallido o costo no justificable: marcar la instancia como **no lista** y pedir una decisión; no silenciar fallas ni inventar disponibilidad, rentabilidad o lecturas.

### Alcance y límites

Esta es una **regla de aprovisionamiento para agentes/operadores** y no un disparador que por sí mismo descubra o alquile instancias. El procedimiento se ejecuta cuando se trabaja explícitamente con una candidata Vast autorizada. Para alquiler autónomo o despliegue totalmente desatendido se requiere implementar y validar un orquestador independiente con permisos y controles financieros específicos.

Otros cambios del repositorio que no traten instancias Vast quedan fuera de esta regla.
