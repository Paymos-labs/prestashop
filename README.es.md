# Paymos para PrestaShop

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | **Español** | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Módulo oficial de pagos de Paymos para PrestaShop. Ofrece pago con stablecoins en el
checkout, redirige al cliente a la página de pago alojada de Paymos y pasa el pedido a
**Payment accepted** en cuanto la transferencia se confirma — a partir de un callback
firmado, nunca por el regreso del navegador del cliente.

Al instalarlo crea tres estados de pedido propios para que un pago cripto no tenga que
tomar prestado un estado con otro significado en tu back office. La factura se emite en la
moneda del carrito y el tipo de cambio queda fijado en el momento en que paga el cliente.

[![Ver vídeo: 12 plugins oficiales de pagos cripto para CMS — conexión en 1 clic (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Vídeo demostrativo (1:12, subtítulos en 6 idiomas):** [Ver en YouTube](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Requisitos

- PrestaShop 1.7.8 hasta 9.x inclusive;
- PHP 7.4 o superior con las extensiones `curl`, `hash`, `json` y `openssl`;
- una tienda en HTTPS — `PS_SSL_ENABLED` activado y la URL de la tienda servida por TLS;
- una cuenta de Paymos con el proyecto destinado a los pedidos de esta tienda seleccionado en el panel.

El SDK de Paymos para PHP viene empaquetado dentro del módulo, así que no hace falta ejecutar
Composer en la tienda. El módulo incluye traducciones propias para inglés, ruso, alemán,
español, turco y chino simplificado.

## Instalación y conexión

1. Descarga el último paquete desde [GitHub Releases](https://github.com/paymos-labs/prestashop/releases/latest).
2. Súbelo y actívalo en **Module Manager** (Gestor de módulos).
3. Abre el proyecto correspondiente en el panel de Paymos; ese proyecto activo se usará automáticamente.
4. Ve a **Module Manager → Paymos → Configure** y haz clic en **Connect Paymos**.
5. Aprueba en Paymos la URL de la tienda y el proyecto actual que se muestran.

El paquete es idéntico para todos los comercios y no incluye ninguna credencial: ni clave API,
ni secreto, ni ID de proyecto, ni secreto de webhook, ni token ni código de dispositivo. Hasta
que se aprueba el paso 5, el módulo está instalado pero inactivo.

Una sola aprobación cubre ambos entornos. En cada entorno Paymos reutiliza tu única clave
Payment activa —o crea una si no hay ninguna— y registra un webhook de tipo Invoice para la
URL de callback de esta tienda, reutilizando uno existente solo si coinciden exactamente URL,
categoría y proyecto, y negándose a sobrescribir un webhook en conflicto en la misma dirección.
El token de dispositivo tiene vida corta y se descarta; el tráfico con la Merchant API va firmado
con HMAC.

La página de configuración conserva entonces un único ajuste —**Mode** (Sandbox o Live)— junto
a un panel de solo lectura que muestra la clave enmascarada, el proyecto vinculado, la URL de
callback y la URL de conciliación. Dos aspectos quedan fuera de esa pantalla: las restricciones
de moneda del módulo, dentro de las preferencias de pago que PrestaShop aplica a cada pasarela,
y la URL de la tienda a la que queda ligada la conexión.

## Almacenamiento de secretos

Las credenciales y el estado temporal del dispositivo se guardan en PrestaShop Configuration como
un sobre cifrado AES-256-GCM con clave derivada de `_COOKIE_KEY_`. Los secretos guardados nunca
se muestran de vuelta en la página de administración ni se pueden introducir a mano. Volver a
conectar es la única forma de cambiarlos y el único paso necesario tras rotar una clave o un
secreto de webhook.

## Estados de pedido

Durante la instalación se añaden tres estados; ninguno se marca como pagado ni registrable
(`logable`), y ninguno envía correo al cliente:

- **Awaiting Paymos payment** — el pedido existe, la factura está abierta y aún no ha llegado ningún pago;
- **Paymos payment confirming** — la transferencia ya está en la cadena acumulando confirmaciones;
- **Paymos payment — manual review** — el dinero llegó pero alguna cifra dejó de cuadrar, o el
  pedido cambió mientras su factura anterior ya estaba pagada, seguía siendo pagable o no pudo
  leerse, por lo que no se emitió ninguna factura nueva.

Los resultados finales pasan a los estados propios de PrestaShop para que el resto de tu back
office funcione con normalidad:

| Evento recibido | Estado del pedido |
|---|---|
| `invoice.confirming` | Paymos payment confirming |
| `invoice.underpaid_waiting`, `invoice.awaiting_payment` | Awaiting Paymos payment |
| `invoice.paid`, `invoice.paid_over` | Payment accepted |
| `invoice.underpaid` | Payment error |
| `invoice.expired`, `invoice.cancelled` | Canceled |

Un pedido pagado nunca retrocede: un evento tardío `cancelled`, `expired` o `confirming` se
anota en las notas privadas del pedido y se descarta. Además, si el importe de un callback de
pago ya no coincide con el pedido, este pasa a **manual review** en lugar de Payment accepted,
con una nota que detalla cada cifra comparada. Cuando el pago se liquida en la cadena, se añaden
como nota el hash de la transacción y su enlace al explorador.

Desinstalar el módulo deja intactos los tres estados. PrestaShop impide borrar un estado que
referencien pedidos históricos, y dejar huérfanos tus pedidos anteriores sería un problema mayor.

## Qué recorre el cliente

Paymos aparece como opción de pago con su propio botón de llamada a la acción. Al elegirla, el
carrito pasa al controlador `validation` del módulo, que crea el pedido en **Awaiting Paymos
payment** y redirige a la página de pago alojada; el importe pagado se registra después desde
el callback verificado, nunca desde el carrito en ese instante.

Un envío doble o una recarga del checkout no genera un segundo pedido ni una segunda factura:
el módulo localiza el pedido existente de ese carrito y envía al cliente a su página de estado.

Si no se puede crear la factura, el pedido pasa a Payment error y el cliente lee una página que
lo explica con claridad en lugar de caer en una redirección en blanco. El mismo controlador ofrece
una pantalla neutral para el caso habitual: quien cerró la página de pago antes de tiempo ve que
el pago sigue confirmándose, junto con un enlace **Continue payment** de vuelta a su factura
abierta. Cuando el checkout decide no sustituir la factura antigua de un pedido, la página indica
que el pedido requiere revisión y pide al cliente que contacte con la tienda, sin enlace de pago.
En todos los casos es una página de solo lectura que nunca cambia el estado del pedido.

## Pruebas en Sandbox

1. Con **Mode** en Sandbox, realiza un pedido desde la tienda.
2. Abre esa factura en el panel de Paymos y dispara el resultado que quieras ensayar. En Sandbox nada se confirma automáticamente por temporizador.
3. Revisa el historial de estados y las notas del pedido en el back office.
4. Cambia **Mode** a Live. La conexión inicial aprovisionó ambos entornos, así que no hay que configurar nada dos veces.

Los pagos en Sandbox no tienen una cadena real detrás, por lo que no se registra hash de
transacción ni enlace al explorador.

## Callbacks y conciliación

El controlador de callback lee el cuerpo sin procesar y la cabecera `X-Webhook-Signature`
(`t={timestamp},v1={hmac_hex}`, HMAC-SHA256 en tiempo constante y compatible con ambos secretos
durante una rotación), y responde con un código de estado HTTP real. Los IDs de evento se guardan
en la tabla propia del módulo para confirmar reenvíos sin aplicarlos dos veces al pedido. Antes de
escribir cualquier estado final, la factura se vuelve a consultar en la Merchant API y se verifica
contra la instantánea guardada.

```text
https://your-store.example/index.php?fc=module&module=paymos&controller=callback
```

La conciliación es la red de seguridad cuando una entrega nunca llega. Toma hasta 50 facturas sin
resolver de las últimas 24 horas y pasa cada una por el mismo mapeador, manteniendo activas la
verificación inversa, la protección de importe y el bloqueo contra retrocesos. Hay dos formas de
lanzarla:

- por HTTP, en la URL con token que figura en la página de configuración — esta es la forma que
  debes dar a tu programador de tareas, ya que PrestaShop 9 bloquea el acceso directo a archivos
  `.php` dentro de `modules/` y un cron apuntando al script recibiría un 403;
- desde la shell, con `php modules/paymos/cron/reconcile.php`, que no necesita token.

Cada ejecución de conciliación revisa también los reembolsos pendientes de Paymos junto con las
facturas sin resolver.

## Reembolsos

El módulo admite reembolsos nativos mediante la Merchant API de Paymos (`/v1/refunds`) en dos
modos calculados por el servidor: `full` (reembolso íntegro de la factura) y `overpayment`
(reembolso del exceso en una factura `paid_over`). Los fondos se envían como una nueva
transferencia saliente en blockchain estrictamente a la dirección `first_sender` verificada que
Paymos registró en la factura pagada: no se introduce manualmente ninguna dirección de billetera
de destino ni se admiten importes parciales arbitrarios.

En el back office de PrestaShop, los empleados con permiso de edición en **Orders** pueden
iniciar reembolsos desde:

- el panel **Paymos refunds** en la página de detalle del pedido (`displayAdminOrderMain`,
  accesible también por ID de pedido en **Module Manager → Paymos → Configure**), con acciones
  **Refund in full** y **Refund overpayment**, la dirección `first_sender` verificada y el
  historial de operaciones;
- el flujo nativo de reembolso completo de pedido de PrestaShop (`paymosNativeRefund`).

Los vales de abono y los correos de reembolso al cliente se retienen hasta que el reembolso en
Paymos alcanza `completed` y queda verificado frente a la Merchant API mediante el controlador
de callback o el endpoint / script CLI de `reconcile`. En modo Sandbox es posible simular los
resultados finales (`completed`, `failed`, `cancelled`) para pruebas de extremo a extremo.

## Resolución de problemas

**La conexión rechaza una URL de tienda en HTTP.** Activa `PS_SSL_ENABLED` y asegúrate de que la
tienda se sirve realmente sobre TLS; el módulo cita ese ajuste en el error en lugar de fallar en
silencio.

**El pedido se queda en Awaiting Paymos payment.** Consulta la URL de callback anterior desde
fuera de tu red: debe responder sin autenticación HTTP y sin redirecciones, ya que no se siguen
redirecciones. Después revisa el registro de PrestaShop buscando entradas `PaymosPrestaShop`. Si
la tienda estuvo inaccesible durante un rato, ejecuta una vez la URL de conciliación y el pedido
se pondrá al día.

**Un pedido está en manual review.** Tiene dos posibles causas y la nota del pedido las distingue.
O bien el total del pedido cambió entre la creación de la factura y la llegada del pago —y la nota
enumera la instantánea, el total actual y el importe del evento—, o bien cambiaron el total del
pedido, el modo o el proyecto mientras la factura anterior de Paymos ya estaba pagada, seguía
siendo pagable o no pudo leerse, en cuyo caso la nota empieza por `"Paymos payment needs manual
review."`. En el segundo caso no se emite factura nueva y se pide al cliente que contacte contigo,
así que revisa primero la factura antigua en el panel de Paymos. Resuelve cualquiera de los dos
casos manualmente.

**Paymos no aparece en el checkout.** Se oculta automáticamente cuando la moneda del carrito no
está entre las monedas permitidas del módulo o cuando el entorno activo no terminó de conectarse.
Comprueba primero las restricciones de moneda y después el panel de conexión.

- [Documentación](https://paymos.io/es/docs/cms-prestashop)
- [Código fuente](https://github.com/paymos-labs/prestashop)
- [Historial de cambios](CHANGELOG.md)
- [Soporte](mailto:support@paymos.io)
