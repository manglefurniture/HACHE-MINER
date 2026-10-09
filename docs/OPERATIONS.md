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
