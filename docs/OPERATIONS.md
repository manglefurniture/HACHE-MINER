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

## Actualización 2026-10: credencial compartida y dispositivos

- `database/002_trusted_devices.sql`: sesiones persistentes con selector/token validador rotatorio; cookie HttpOnly/Secure/SameSite, 30 días, revocable por administrador.
- `database/003_salad_targets.sql`: lista dinámica de organizaciones y proyectos. Semillas HACHE / prl-tests e INTERACTIVE / default. Nuevas organizaciones se agregan o pausan desde el panel, sin tocar GitHub.
- La clave API Salad se almacena una sola vez con nombre `salad:api-key`. Las credenciales antiguas de HACHE e INTERACTIVE solo sirven de compatibilidad de lectura temporal; no volver a configurarlas por separado.
- Antes de activar una versión con estas tablas, ejecutar como root el script versionado `deploy/apply-private-migrations.sh` desde su release. Crea un backup de la DB en directorio root 0700, aplica migraciones aditivas y verifica filas iniciales.
- El backup SQL local **no sustituye** un respaldo externo cifrado y recuperable de `master.key`. No guardar API keys hasta preparar el respaldo externo.
- La integración de Salad **solo lee** grupos y logs; añadir organizaciones no incrementa cuotas, no crea recursos, no elude las políticas del proveedor.

## Kryptex PRL: integración verificada (octubre 2026)

- La API pública de Kryptex respondió con `unconfirmed` y `confirmed` en `/prl/api/v1/miner/balance/{address}`; se guardan en `pool_observations.pending_prl` y `confirmed_prl` como saldos **PRL**, no USD ni ingresos del intervalo.
- `/prl/api/v3/miner/workers/{address}` devuelve `results[]`; los workers con `status=online` proporcionan `avg_hashrate_30m` en H/s. Se registra hashrate del **pool por billetera**, no de una GPU o de una organización de Salad.
- Varias organizaciones pueden etiquetar la misma billetera: se consulta solo una vez por ciclo. No sumar sus saldos al consolidar por organización; provocaría doble contabilidad.
- Límite: dos billeteras únicas por ciclo con peticiones de máximo seis segundos; las demás rotan en siguientes ciclos. No se generan datos ficticios si falla balance o workers.
- `wallets` se configura desde el panel privado, con direcciones **públicas** PRL; nunca guardar frases semilla.
- Los balances `pending` / `confirmed` son fotografías del saldo, no ganancias por hora, ni pagos acumulados, ni ingresos netos. Falta incorporar pagos verificados, precio efectivo de venta de PRL y los gastos reales facturados.
- La API pública de SaladCloud utilizada para gestionar grupos e instancias no ofrece una consulta documentada de facturación ni de saldos. El gasto estimado por tiempo y tarifa confirmada no puede presentarse como gasto real.
- El recolector de Salad tiene prioridad; Kryptex se consulta al final y los tiempos por petición están limitados.

## Monitor unificado de Salad (octubre 2026)
- **Monitor GPU** es la primera migración del panel del monitor antiguo a HACHE-MINER. El inventario procede de las agrupaciones e instancias observadas por el recolector para **todos los proyectos registrados** en el panel. No depende de una lista fija de diez máquinas ni limita la visualización a estados `running`.
- Incluye grupos `pending`, `stopped`, asignación parcial, réplicas listas y grupos del histórico con marca `sin lectura reciente`. Identifica instancias por ID y toma su último estado observado durante una ventana de 15 minutos; no confunde muestras repetidas con más máquinas. Una réplica lista **no equivale** a shares aceptadas.
- Los datos de GPU, hashrate, potencia y temperatura se leen del texto que el recolector almacena en el grupo. **Solo** se muestran como indicador asociado al grupo con una única réplica observada y solicitada, nunca repartiendo el valor del grupo entre varias instancias.
- El monitor anterior de Hache Natación conserva sus alertas ntfy y la política de auto-reallocate. No apagamos su timer ni duplicamos acciones ni permisos. Para retirar ese monitor se requiere migrar y probar por separado las alertas, transiciones, umbrales y prevención de acciones duplicadas.
- La API de Salad devuelve grupos por organización **y proyecto**. El sistema observa cada proyecto registrado en Configuración; las organizaciones/proyectos nuevos requieren registrarse, no pueden inferirse a partir de otros proyectos. Si Salad rechaza o limita una consulta, se conserva el histórico y se marca ausencia de cobertura.
- **Finanzas** muestra por separado sumas de cargos registrados con comprobante (histórico) y costos parciales observados en las últimas 24 horas, únicamente cuando hay muestras y una tarifa confirmada. El cálculo parcial no es el gasto facturado, y los saldos pendientes/confirmados en Kryptex no son ventas ni utilidades netas. No se adjudican a organizaciones si comparten billetera.


## Reasignación manual protegida (2026-10)
- La intervención manual es un **override voluntario**: no consulta umbrales de hashrate, rentabilidad ni caída de rendimiento. Por ejemplo, el administrador puede solicitar otra máquina incluso con **140 TH/s** o más. Nunca inicia acciones por sí sola.
- El temporizador original `hache-salad-monitor.timer` y sus umbrales de auto-reallocate **se mantienen habilitados e independientes**. El botón no desactiva, pausa ni cambia la lógica automática. Durante la migración no se promete exclusión global entre servicios: el supervisor automático podría solicitar el mismo cambio en un intervalo coincidente.
- El dashboard muestra el cooldown manual de 15 minutos por **instancia exacta** con base en su auditoría, sin consultas SQL individuales por botón. La pantalla de confirmación vuelve a verificar el cooldown; el backend lo impone bajo lock.
- Mensajes de aceptación solo proceden de un resultado HTTP 202 y se conservan como aviso efímero del lado servidor, nunca mediante parámetros GET manipulables por el navegador.
- En Monitor GPU, cada instancia individual identificada y observada recientemente como `running + ready + started` muestra «Reasignar instancia». **No hay una acción global por grupo**: el ID exacto evita mover las otras réplicas.
- El primer clic solo abre una pantalla de validación. Se requiere sesión administrativa, token CSRF, desafío aleatorio de sesión de tres minutos, volver a escribir `REASIGNAR` y **contraseña actual** (aunque el dispositivo esté recordado). Cinco fallos de contraseña en quince minutos suspenden la autorización manual hasta que expire la ventana.
- Antes del POST oficial de Salad se verifican de nuevo grupo/proyecto habilitado y última instancia observada en MariaDB y se obtiene su estado actual desde la API de Salad con el ID exacto. La petición de reasignación se realiza **únicamente al confirmar**, mediante `POST /organizations/{org}/projects/{project}/containers/{group}/instances/{instance_id}/reallocate`.
- Dos solicitudes manuales concurrentes para el mismo ID se serializan con un advisory lock de MariaDB, y un registro de intención previo al POST bloquea repetir la misma instancia durante 15 minutos. Ante timeout se asume resultado incierto: no existe reintento automático.
- Respuesta HTTP 202 significa **solicitud aceptada**, no nueva GPU ya asignada. El siguiente ciclo del recolector comprobará el cambio real.
- La política automática existente de HACHE Natación continúa funcionando por separado y todavía puede coincidir temporalmente con una petición manual; la comprobación en vivo reduce riesgo, pero **no constituye exclusión mutua entre ambos servicios**. No activar acciones duplicadas en HACHE-MINER durante la futura migración de reglas. Para coordinación total habrá que unificar el motor de reasignación y retirar el antiguo con pruebas específicas.
- Se registran intención y resultado con hash del destino y usuario administrador en audit_events, sin contraseña ni API key. Esta función **no se ejecuta en tests**, ni en tareas de recolección. Puede interrumpir temporalmente la minería; solo el administrador debe confirmar cada ejecución.


## Tarjetas operativas por instancia y retención visual 8 horas (octubre 2026)
- El monitor **solo muestra** los grupos que Salad informó a HACHE-MINER durante las últimas **8 horas** (`group_state.last_seen_at`). No ejecuta DELETE ni modifica históricos, cargos o auditorías. Grupos creados pero todavía asignando siguen visibles si Salad los reportó recientemente.
- Cada réplica tiene su propia tarjeta con el **último hashrate individual confirmado por log**, su serie de hasta 24 muestras conservadas en `miner_observations` (aproximadamente 2 horas cuando la lectura es cada cinco minutos), GPU, vatios observados y acceso manual protegido «Buscar otro nodo».
- Las mediciones se vinculan solamente si el log de Salad contiene un `instance_id` (o `machine_id`) que coincide con una instancia conocida; en grupos de una única instancia se permite un log sin etiquetas **solo** si es posterior al inicio del estado running de ese nodo. Las líneas sin identidad en grupos multirréplica se **ignoran** para la atribución individual. Si no hay identificación fiable, la tarjeta indica «Sin medición individual» y **no calcula un total falso**.
- El estado del contenedor sigue separado de la minería: `ready`/ `started` por sí mismos no acreditan hashrate. La métrica actual requiere observación reciente (10 min), nodo listo y fuente trazable. Las curvas se forman con la tabla ya existente `miner_observations`, sin migración de base de datos.
- Semáforo económico **orientativo**, aplicable solo a nombres de grupos `prl-*` con RTX 4070 Ti SUPER y prioridad Salad `low`: **verde** 140 TH/s o más, **amarillo** más de 125 y menos de 140, **rojo** 125 TH/s o menos. El margen se calcula respecto a 125 TH/s. Son referencias de una hipótesis puntual, **no ganancia neta ni rentabilidad garantizada**; no se usan para detener/reasignar automáticamente instancias. Quantus/QTC, otras GPU y prioridades distintas quedan con estado económico neutro hasta contar con datos y tarifas verificadas.
- El botón manual sigue disponible para cualquier instancia que reúna las condiciones de seguridad, **independientemente de su color/TH/s**. La regla automática antigua queda intacta.
- El recolector usa la ventana habitual de logs (siete minutos, página de 100). Si la API no incluye metadatos por instancia, es posible que algunos grupos multirréplica muestren mediciones desconocidas. No se divide la tasa del grupo entre cuatro ni se inventan datos para rellenar gráficos.

## Contabilidad: libro de comprobantes (2026-10)

- La pestaña **Finanzas** separa tres dimensiones: **caja realizada USD/USDT equivalente**, **consumo facturado de Salad** y **PRL en pool o tránsito**. No sumar recargas de Salad y consumo de GPU como dos gastos. El flujo de caja registrado no equivale a utilidad neta.
- La tabla `accounting_events` se incorpora con `database/004_accounting_events.sql` **solo** mediante el script root `deploy/apply-accounting-migration.sh`. Este crea un respaldo privado de `hache_miner`, no modifica `hache_natacion`, y no cambia la minería. La nueva interfaz detecta ausencia de la tabla y se presenta como pendiente, sin bloquear el monitor.
- Comprobantes manuales: `salad_topup` (salida de caja), `salad_refund` (entrada), `prl_sale` (venta realizada: PRL, importe USD/USDT bruto, comisión y neto), `other_cost`, `other_income`, `prl_payout` (pago del pool en moneda PRL sin caja USD) y `prl_transfer` (movimiento entre cuentas propias, sin venta). Cada evento tiene referencia única obligatoria, fecha UTC y autor; no se editan/eliminan filas silenciosamente.
- Las ventas de una billetera PRL compartida pertenecen por defecto al fondo **compartido**, nunca se adjudican arbitrariamente a HACHE o INTERACTIVE. Los cargos de Salad facturados sí se conservan por organización en `reconciled_charges` con periodo y documento.
- El informe de entradas menos salidas incluye exclusivamente **movimientos con comprobantes que el administrador ya registró**. No es saldo bancario ni rentabilidad completa, y no debe inferirse cero si faltan comprobantes o solo hay transferencias PRL.
- Para activar tras desplegar en `main`, conectar como root por un canal seguro y ejecutar: `bash /srv/hache-miner/current/deploy/apply-accounting-migration.sh`. El comando no imprime claves. Confirmar salida `HACHE_MINER_ACCOUNTING_SCHEMA_OK` y no compartir su respaldo SQL.
- No automatizar imports desde capturas sin validar sus referencias o transacciones. Cobros en SafeTrade, pagos USDT/USDC y cargos Salad requieren evidencia verificable del movimiento; la API pública de Salad utilizada por el monitor no proporciona costos reales de facturación por transacción.


## Importación de ocho recargas Salad verificadas por recibos Stripe — octubre 2026

- Fuentes: ocho correos de recibo de Salad/Stripe y avisos de recarga correspondientes, fechados 2026-10-04 a 2026-10-08 en `fernandez83@gmail.com`. Los comprobantes personales **nunca se publican en GitHub**.
- HACHE: 7 recargas, total US$40. INTERACTIVE: 1 recarga, US$5. Total US$45 de **crédito prepago comprado**. No es consumo facturado ni beneficio negativo: el importe gastado exige dato de saldo/uso de Salad.
- Importador genérico: `bin/import-salad-receipts.php`, ejecutado solo como root mediante `deploy/import-salad-receipts.sh`. La fuente CSV queda en `/srv/hache-miner/private-imports/salad-receipts-2026-10.csv` con permisos directorio 0700 y fichero 0600, ambos bajo control local. El comando requiere SHA-256 explícito del CSV, verifica ocho filas exactas y total por organización, usa la misma validación del panel y evita duplicar recibos. Si una referencia ya existe pero su importe/org no coincide, revierte toda la operación.
- Antes de modificar el libro, el wrapper crea un SQL backup root-only de `hache_miner`. No toca otras bases ni conecta a Salad. El primer uso requiere ejecución explícita como root del propietario: `bash /srv/hache-miner/current/deploy/import-salad-receipts.sh 96f3c48f227f4bfa85383b7f42e1136fb922e572c626ba3f68e70b1628ea8ba3`.
- La referencia original de cada recibo Stripe se convierte en `stripe:salad:<número>` para garantizar unicidad; fechas de las notificaciones de recarga UTC. Se registran como `salad_topup`, no como cargos de consumo, en el libro de caja.
- Los cargos reales consumidos no están documentados en estos recibos (indican `Cloud Usage Credit`). Hasta recuperar el saldo de crédito o desglose de consumo oficial de Salad, el gasto devengado es **desconocido**, no US$45.


## Capturas oficiales Salad Credits (corte fechado, no gasto por evento)
- La captura de facturación de Salad entrega por organización *Total Issued*, *Total consumed*, *Available credits*, *Total expired*; son **totales acumulados**, no ingresos del día ni facturas nuevas. Guardarlos en una tabla histórica separada, `salad_credit_snapshots` (migración 005), con referencia única y fecha. Cada nueva captura sustituye únicamente la **vista de saldo actual** de esa organización; nunca incrementa cargos registrados.
- La conciliación requiere `issued=consumed+available+expired`. Una discrepancia hace fallar la entrada antes de tocar la base de datos. La cifra *issued* puede contener créditos gratuitos o de promoción, por lo que **no implica** que el usuario pagó el mismo número de USD.
- Evidencia sensible: capturas y CSV con importes reales se conservan fuera de GitHub. El primer paquete de dos capturas, una por organización, quedó en `/srv/hache-miner/private-imports/salad-credits-2026-10-09.csv` (0600). El importador root `bin/import-credit-snapshots.php` acepta una huella SHA-256 de ese archivo y no sube ninguna captura a servicios externos.
- Para activar, el propietario ejecuta como root `bash /srv/hache-miner/current/deploy/apply-credit-snapshots-migration.sh` y verifica `SALAD_CREDITS_SNAPSHOTS_READY`. Después importa el CSV con `php -d auto_prepend_file=/etc/hache-miner/load-env.php /srv/hache-miner/current/bin/import-credit-snapshots.php <sha256 del fichero privado>`. La migración crea un respaldo root-only de la base y no detiene el monitor.
- El libro `accounting_events` conserva transferencias/reembolsos, recargas por recibo y ventas. **Nunca** insertar las cifras acumuladas `consumed` de una captura en `reconciled_charges` como si cada captura fuera un cargo nuevo. Para calcular consumo entre cortes sucesivos se toma la diferencia de valores acumulados de la misma organización, verificando posibles ajustes de Salad.
- Ocho recibos Stripe por 45 USD permanecen una **parte documentada** de los pagos, no el total garantizado. Los movimientos Solflare deben conciliarse con los créditos emitidos antes de sumarlos como pagos separados y evitar doble contabilización.


## INTERACTIVE — protección automática por réplica PRL (2026-10-09)
- El monitor automático original de HACHE continúa en `hache-salad-monitor.timer`. INTERACTIVE tiene su **propio servicio** en HACHE-MINER `hache-miner-interactive-auto.timer` con horario cada cinco minutos, después del ciclo de recolección. No sustituye, modifica ni reinicia el monitor HACHE.
- La política automática solo puede actuar en organización **interactive**, proyectos habilitados, grupos `running` de prioridad Salad `low`, y GPU **RTX 4070 Ti SUPER** cuyo log de Salad contiene `[pearlhash]` en los últimos 30 minutos. Los grupos con nombres Quantus que realmente ejecuten el algoritmo PRL pueden ser elegibles si **el algoritmo está probado**. La minería Quantus/QTC, otros algoritmos y GPU sin identificación quedan sin automatizar.
- Cada réplica se evalúa **por su ID individual**, incluso dentro de grupos de cuatro instancias. Exige al menos **tres muestras consecutivas verificadas a 125 TH/s o menos**, espaciadas aproximadamente cinco minutos, abarcando al menos nueve minutos, última lectura de menos de ocho minutos y contenedor `running+ready+started`. No se aceptan muestras repetidas o faltantes. El valor 125 TH/s es un umbral operativo orientativo, no una afirmación de pérdidas económicas en USD.
- Antes de ejecutar un POST verifica otra vez datos de MariaDB, algoritmo, instancia exacta y estado en vivo de Salad; usa exactamente el mismo bloqueo MySQL/fingerprint que el botón manual. Respeta solicitudes manuales recientes durante 60 minutos y nunca vuelve a solicitar una asignación automáticamente para el mismo ID de instancia después de registrar una intención, incluso ante timeout incierto. La nueva réplica tendrá un ID diferente y podrá evaluarse de nuevo.
- Un `HTTP 202` significa aceptado, no nuevo nodo listo. El servicio registra `auto_reallocate_intent`, `auto_reallocate_accepted` o `auto_reallocate_unknown` sin exponer claves. **Máximo tres solicitudes por ciclo** para evitar oleadas de cambios.
- La ejecución independiente es **modo de simulación por defecto** si falta `MINER_INTERACTIVE_AUTO_ENABLED=1`. La instalación privilegiada exige primero un dry-run real como usuario restringido hache-miner, luego instala `hache-miner-interactive-auto.service/.timer`. Solamente root puede activarlo mediante:
  `bash /srv/hache-miner/current/deploy/enable-interactive-auto.sh`.
- Un operador puede detener este componente nuevo sin afectar al automático de HACHE con `systemctl disable --now hache-miner-interactive-auto.timer`. El monitor visual y el botón manual siguen funcionando; para repetir instalación después de deshabilitar, solo `systemctl enable --now hache-miner-interactive-auto.timer` si los archivos de unidad y las pruebas ya están validados.
- El semáforo visual se habilita para cualquier grupo con logs recientes comprobados de `[pearlhash]` y RTX 4070 Ti SUPER Low, aunque el nombre del grupo empiece por `quantus-`. Sin prueba del algoritmo permanece en estado neutro: nunca deducir criptomoneda a partir del nombre solamente.


## Total observado TH/s en cabecera de Monitor GPU
- La primera cifra del monitor es la **suma de las últimas medidas individuales verificadas** para instancias `running` y `ready` de grupos observados recientemente y proyectos habilitados. Solo se aceptan muestras de hasta **10 minutos** de antigüedad (y no más de 2 minutos en el futuro para tolerancia de reloj).
- Cada ID de instancia se suma **una sola vez** en su grupo, incluso si hay muestras duplicadas. Se excluyen grupos borrados/históricos, detenidos, instancias sin asignar y medidas antiguas. Se toma la observación más reciente comprobable y se muestra el intervalo UTC entre la más antigua y más reciente incluidas.
- Debajo del total se muestra `X de Y` instancias listas con tasa confirmada y cuántas carecen de datos. El indicador **parcial** evita presentar una medición incompleta como si fuese toda la potencia. Si no hay ninguna tasa verificada, se muestra `Sin lecturas recientes`, **no 0 TH/s**; un cero efectivamente medido sí se muestra como `0.00 TH/s`.
- También se exhibe el subtotal de HACHE e INTERACTIVE. Unidades **TH/s** de las lecturas reales de los logs; tasas de algoritmos diferentes no se pueden comparar en rentabilidad. No es promedio temporal, media del grupo ni ingresos confirmados; es una suma observacional de mediciones hechas en momentos distintos dentro de una ventana máxima de diez minutos.
- Este cálculo no modifica ni invoca el sistema automático de reasignación, las tarjetas individuales ni las credenciales de Salad.


## Lecturas universales de GPU y grupos multirréplica (2026-10-09)
- **Cualquier modelo de GPU** se lee si el minero informa un log de dispositivo individual con `GPU0 #0`, `GPU 0`, `GPU[0]`, etc. y una tasa en `TH/s`. Los campos W/ventilador/temperatura son opcionales; su ausencia no oculta el hashrate. La lectura y el umbral económico son mecanismos separados: RTX 4070 Laptop y otros modelos pueden mostrar TH/s aunque carezcan de umbral de rentabilidad validado.
- La consulta de logs de Salad puede aportar `resource.labels.instance_id`, variantes en camelCase, `machine_id` o identificar una instancia mediante su UUID completo escrito en el log. El recolector atribuye una tasa individual **solo** al nodo con identidad única verificada. Etiquetas contradictorias o desconocidas se descartan; en grupos con 2+ GPU, **nunca** se reparte el hashrate de un log genérico entre réplicas.
- Para grupos con 2+ réplicas y logs sin ID, el panel muestra la **última línea detectada del grupo**, su GPU, hora y recuento reciente bajo la advertencia `no es el total` y `sin atribución`. No se añade esa lectura al total de TH/s general ni se presenta como tasa de ninguna máquina.
- Esta corrección no modifica el algoritmo de reasignación automático, sus umbrales, el control manual ni el ritmo del recolector. Si un grupo sigue con `Sin medición individual` y muestra solo log de grupo, es preciso verificar en Salad qué metadatos de instancia entrega o incorporar IDs de worker en la imagen minera antes de asignar tasas a esas réplicas.


## Proveniencia de lecturas y coste horario visible (octubre 2026)
- Corrección a observaciones Codex PR #29: el recolector, al recibir el log original de Salad con `resource.labels`, lo clasifica **antes de descartar las etiquetas** y solo añade al texto saneado uno de los marcadores `[MONITOR_GPU_ATTRIBUTED]`, `[MONITOR_GPU_UNATTRIBUTED]` o `[MONITOR_GPU_UNKNOWN]` si detecta una línea de GPU real. **No almacena IDs de instancia adicionales** ni modifica el esquema. Etiquetas explícitas conflictivas/de otra instancia y UUID de trabajador sin correspondencia son **UNKNOWN**, nunca declaradas anónimas. Registros anteriores sin marcador tienen identidad desconocida y se excluyen de la tarjeta de evidencia anónima para evitar falsos diagnósticos. La evidencia efectivamente anónima se muestra incluso si todas las GPU ya tienen hashrate individual.
- Corrección a observaciones Codex PR #30: el diagnóstico privado declara explícitamente `PAGE_SIZE_LIMIT=100` y `MAY_BE_TRUNCATED=YES` cuando la consulta llena la primera página; no se puede declarar exhaustiva una consulta de 25 minutos con más eventos que los solicitados. Se **elimina** la impresión de cadenas de modelo derivadas de regex porque podían incluir UUID u otros identificadores privados. La consulta queda limitada a la primera página, sin aumentar tráfico o exponer secretos.
- Cabecera del monitor: junto a TH/s aparece **USD/h estimados** según el catálogo `gpu_rates`: tarifa explícita para exactamente la organización + clase de GPU Salad + prioridad del grupo multiplicada por instancias individuales `running/ready/started` con última observación hace <=10 minutos. Sin tarifa exacta la GPU se marca *sin precio* y el total se presenta **parcial**; nunca se asigna USD 0 por falta de datos. Sin máquinas con tarifa, se muestra `Sin tarifa`, no `$0/h`.
- El cálculo monetario es independiente de que SRBMiner reporte TH/s: una GPU lista puede consumir crédito aunque todavía no genere shares. No se cuentan réplicas solo solicitadas o en asignación ni grupos deshabilitados o sin observaciones recientes; Salad podría facturar otros estados y la cifra **no es una factura ni consumo oficial al instante**, sino una estimación por tarifa configurada. El desplegable muestra detalle por organización, clase y prioridad, unidades y subtotal, y enlaza `Configuración → Tarifas de GPU` para completar tarifas faltantes.
- No se cambian políticas económicas automáticas, umbrales ni acciones de reallocate; el recolector continúa exclusivamente de lectura respecto a Salad.


## Instancias cerradas: evidencia del último snapshot (2026-10-10)
- El inventario "actual" y sus TH/s/estimación USD/h se basan **únicamente** en instancias que Salad devolvió en el último GET `.../instances` válido de cada grupo. Antes se acumulaban IDs de los últimos 15 minutos: una GPU ya retirada podía conservar una lectura roja y sumarse al total aunque el grupo siguiera `running`. Los históricos permanecen en `miner_observations` sin borrarse; se filtran para la visualización comparando `observed_at` de las filas contra `group_state.last_seen_at`, ambos sellados con la **misma marca UTC de muestra** en el recolector.
- Un snapshot de grupo exitoso con **cero instancias** invalida inmediatamente todas las tarjetas anteriores y elimina sus TH/s/costos del resumen, sin crear registros falsos. Si el listado de grupos completo de Salad deja de incluir uno antes conocido, el recolector marca su estado `not_listed` una sola vez, conserva su historial y no presenta sus antiguas GPU como operativas.
- Si la petición de instancias falla o devuelve un objeto no verificable, el estado se marca `unverified` y no se inventa actividad. No se considera la ausencia como una parada confirmada. Si la lista principal tiene registros malformados o paginación sin terminar, no se reconcilian ausencias.
- La interfaz indica que es la **última consulta de Salad**, no telemetría instantánea: el sondeo corre aproximadamente cada cinco minutos, de modo que un cierre posterior al último sondeo todavía puede tardar un ciclo en reflejarse. No se inicia una consulta bajo demanda, ni se incrementa el tráfico a Salad.
- Cambios solo de recolección de metadatos y presentación; sin migración, borrado de históricos, ajustes de permisos, reasignaciones, reinicios de GPU ni modificaciones a los tres temporizadores.


## Catálogo de clases de GPU para tarifas
- En Configuración → **Tarifas de GPU**, el ID de Salad se elige de un desplegable construido desde todas las clases no vacías de `group_state.gpu_class` (sin límite de antigüedad o restricción de estado) y `gpu_rates.gpu_class`, sin duplicados. No se consultan las API de Salad para dibujar el formulario, ni se actualiza la lista al instante: depende de lo ya registrado en el monitor.
- El segundo campo «Otra GPU» es opcional para una clase de Salad aún no presente en el historial; si se escribe, reemplaza la selección del desplegable. Esto mantiene compatibilidad con futuras GPU sin listas de modelos permitidos. Un ID de Salad es obligatorio al guardar, y el valor de USD/h continúa siendo manual y confirmado.
- Las clases antiguas de un grupo que haya **cambiado de clase en el mismo nombre de grupo** pueden no estar en `group_state` porque la fila se sobrescribe. Permanecen seleccionables si se llegó a guardar su tarifa. Este cambio no afirma reconstruir clases borradas ni crea un historial retroactivo.
- Este cambio es exclusivo de la interfaz de tarifas y la consulta de registros existentes. No altera la API de Salad, importes, reglas de rendimiento, extracción de TH/s, monitor de producción, timers o auto-reallocate.
