# Arquitectura de facturación preparada y desactivada

## Entidades

- `billing_plans`
- `billing_accounts`
- `billing_provider_products`
- `billing_subscriptions`
- `billing_access_grants`
- `billing_webhook_events`
- `mobile_app_devices`

## Plan inicial

- Código: `rhino_crm_access`
- Precio de referencia: `999` centavos USD.
- Intervalo: `MONTH`.
- Estado: `DRAFT`.
- Proveedores: Stripe, Google Play y Apple en estado `DRAFT` y con `enabled=false`.

## Comportamiento actual

`BillingAccessService` concede acceso por `billing_disabled` mientras:

```text
BILLING_ENABLED=false
```

o:

```text
SUBSCRIPTION_ENFORCEMENT_ENABLED=false
```

No existe checkout en esta versión.
