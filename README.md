# HACHE-MINER

Plataforma independiente de observabilidad y control histórico de minería PRL, desarrollada por Hache Interactive.

**Estado: desarrollo.** La aplicación no está desplegada ni conectada a servicios reales hasta completar los requisitos de puesta en producción.

## Principios
- Repositorio **público**, código abierto al examen; **ninguna credencial, clave API, frase semilla, clave privada ni datos operativos reales** se suben a GitHub.
- Panel privado, administrador único inicial, autenticación por sesión, CSRF, auditoría y cifrado autenticado de secretos de proveedores.
- Integración SaladCloud (organizaciones `hache` e `interactive`) y API pública de Kryptex. Solo consultas de lectura en la primera etapa.
- MariaDB para historial forward-only, con tiempos UTC; presentación en America/Cancun.
- Separar **costo estimado**, **cargo real conciliado**, **producción pendiente**, **confirmada** y **cobros realizados**.
- No almacenar semillas o claves privadas de billetera en el VPS. Solo direcciones públicas y credenciales API estrictamente necesarias.
- Flujo GitHub → CI → PR → revisión → merge → despliegue autorizado → verificación. Nunca editar código de producción a mano.

Consulta `docs/OPERATIONS.md` para los prerrequisitos de despliegue y el modelo de seguridad. Inspirado en los contratos de Hache-Base (Core → Theme → Extensions) sin duplicar ni depender de Hache Natación.
