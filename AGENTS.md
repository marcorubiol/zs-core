# zs-core: motor de la flota

El núcleo del sistema y el contrato git llegan por `~/Developer/AGENTS.md`. Contexto del proyecto: `~/Zerø System/03_AGENCY/Fleet/_context.md`; decisiones en su `_decisions.md`.

## Releases

Una release sin sufijo llega sola a los 16 sitios de clientes en menos de 24 h, sin rollback ni olas. Por eso:

- Commit y push a `main`: libres. `main` no despliega.
- Tag y release: nunca sin OK de Marco. Siempre con `deploy/release.sh`, nunca a mano.
- Primero canary (`deploy/release.sh X.Y.Z --canary`: prerelease, la flota no la toma). Se promueve a `X.Y.Z` solo tras validarla.
- La firma es la ceremonia de Marco: la clave nunca pasa por mí, por argv, por disco ni por CI.
- `deploy/fleet-push.sh` y `--push` fuerzan la actualización en la flota: siempre con OK.
- Un módulo nuevo lleva tests registrados en `release.yml` y en `release.sh`.
