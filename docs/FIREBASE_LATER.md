# Firebase Authentication en Rhino CRM

Firebase Authentication está integrado como identidad móvil opcional. No utiliza Firestore, gRPC ni Protobuf.

## Flujo

```text
Android inicia sesión en Firebase
        ↓
Obtiene Firebase ID Token
        ↓
POST /api/v1/auth/firebase/exchange
        ↓
Laravel verifica token, revocación, correo y usuario
        ↓
Laravel emite sesión Rhino revocable
        ↓
Android utiliza el token Rhino en la API
```

MySQL sigue almacenando socios, usuarios, sesiones, equipos, prospectos, eventos, mediciones, ventas e informes.

Consulte `UPGRADE_FIREBASE_AUTH_2026-07-21.md` para instalación, configuración y pruebas.
