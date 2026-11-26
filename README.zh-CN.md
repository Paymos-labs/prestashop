# Paymos PrestaShop 支付插件

[English](README.md) | **简体中文** | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | [Türkçe](README.tr.md)

Paymos 官方 PrestaShop 稳定币支付模块。买家在结账页选择 USDT/USDC 收款方式后，跳转至 Paymos 托管收银台完成转账；链上确认后，模块仅凭带 HMAC-SHA256 签名验证的回调将订单推进至 **Payment accepted（已收款）**，绝不依赖买家浏览器返回。

安装后模块会新建三个专属订单状态，避免加密支付借用后台里语义不同的现有状态。账单以购物车币种计价，并在买家付款的瞬间锁定汇率。

[![观看视频：12 款官方 CMS 稳定币支付插件 — Paymos 一键连接](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **视频演示（1:12，内置 6 种语言字幕）：** [在 YouTube 上观看](https://www.youtube.com/watch?v=VMLUGVumYkg)

## 环境要求

- PrestaShop 1.7.8 至 9.x（含 9.x）；
- PHP 7.4 及以上版本，需启用 `curl`、`hash`、`json` 和 `openssl` 扩展；
- HTTPS 独立站店面——已开启 `PS_SSL_ENABLED`，且店铺 URL 通过 TLS 提供服务；
- 一个 Paymos 账户，并在控制台选定用于承接该店面订单的项目。

安装包已内置 Paymos PHP SDK，无需在网店服务器运行 Composer。模块自带英语、俄语、德语、西班牙语、土耳其语和简体中文界面翻译。

## 安装与一键连接

1. 从 [GitHub Releases](https://github.com/paymos-labs/prestashop/releases/latest) 下载最新安装包。
2. 在 **Module Manager（模块管理器）** 中上传并启用该模块。
3. 在 Paymos 控制台打开目标项目；连接时会自动使用该当前项目。
4. 打开 **Module Manager → Paymos → Configure**，点击 **Connect Paymos**。
5. 在 Paymos 页面核对并批准显示的店铺 URL 和当前项目。

所有商户下载的是同一个安装包，内不含任何凭证——没有 API 密钥、secret、项目 ID、webhook secret、token 或 device code。在第 5 步批准之前，模块虽已安装但不会激活任何通信。

一次批准同时打通 Sandbox 和 Live 两套环境。在每套环境中，Paymos 都会复用你唯一的活跃 Payment 密钥（若尚不存在则自动创建），并为当前店铺回调地址注册一个 Invoice webhook——仅当现有 webhook 的 URL、类别和项目全部一致时才会复用，绝不覆盖同一地址上的冲突 webhook。设备授权 token 为短时凭证，用完即弃；此后的 Merchant API 通信全部采用 HMAC 签名。

连接完成后，配置页仅保留一项设置——**Mode（模式）**，可在 Sandbox 与 Live 之间切换——旁边是只读状态面板，展示脱敏后的密钥、绑定的项目、回调 URL 以及对账 URL。有两项设置位于该页面之外：模块的币种限制（位于 PrestaShop 对所有支付网关生效的支付偏好设置中）以及连接所绑定的店铺 URL。

## Secret 存储

凭证与临时设备状态经由 `_COOKIE_KEY_` 派生的密钥，以 AES-256-GCM 封装形式存入 PrestaShop Configuration。已保存的 secret 不会回显到后台管理页面，也不支持手动填写。如需更换凭证，或在密钥、webhook secret 轮换后更新配置，唯一的做法是重新连接。

## 订单状态

安装时会新增三个订单状态，均不标记为已付款或可入账（logable），也不会向买家发送邮件：

- **Awaiting Paymos payment（等待 Paymos 付款）** —— 订单已创建，账单已开启，尚未收到转账；
- **Paymos payment confirming（Paymos 付款确认中）** —— 链上已检测到转账，正在等待区块确认；
- **Paymos payment — manual review（Paymos 付款 — 需人工审核）** —— 款项已到账但金额不再吻合，或订单在旧账单已付款、仍可支付或无法读取期间发生变动，因此未签发新账单。

终态结果会落入 PrestaShop 原生订单状态，让后台其余流程照常运转：

| 回调事件 | 订单状态 |
|---|---|
| `invoice.confirming` | Paymos payment confirming |
| `invoice.underpaid_waiting`、`invoice.awaiting_payment` | Awaiting Paymos payment |
| `invoice.paid`、`invoice.paid_over` | Payment accepted |
| `invoice.underpaid` | Payment error |
| `invoice.expired`、`invoice.cancelled` | Canceled |

已付款订单绝不会回退：迟到的 `cancelled`、`expired` 或 `confirming` 事件仅记录到订单私密备注并直接丢弃。若已付款回调的金额与当前订单不再一致，订单会转入 **manual review（人工审核）** 而非 Payment accepted，并在备注中逐项列出参与比对的金额。款项在链上结算后，交易哈希和区块浏览器链接会自动写入订单备注。

卸载模块后会保留这三个订单状态。PrestaShop 不允许删除历史订单仍在引用的状态，且让过往订单失去状态关联会造成更严重的后果。

## 买家结账流程

Paymos 会以独立支付方式和专属行动按钮出现在结账页。买家选择后，购物车会交由模块的 `validation` 控制器处理：控制器先以 **Awaiting Paymos payment** 状态创建订单，再重定向至托管收银台；实际已付金额会在后续经过验证的回调到达时写入，而不会在此时直接取用购物车金额。

重复提交或刷新结账页不会生成第二笔订单和第二张账单——模块会查找该购物车对应的现有订单，并将买家引导至其状态页。

若账单完全无法创建，订单会转入 Payment error，买家会看到明确说明原因的页面，而不是落入空白跳转。同一控制器在常规场景下提供中性状态页：过早关闭支付页的买家会看到款项仍在确认中的提示，以及返回未结账单的 **Continue payment（继续付款）** 链接。若结账流程因保护机制未替换订单的旧账单，页面会告知订单需要审核并请买家联系商家，同时不提供付款链接。该状态页在任何情况下都为只读——绝不会变更订单状态。

## 在 Sandbox 中测试

1. 保持 **Mode** 为 Sandbox，通过前台店面下一笔订单。
2. 在 Paymos 控制台打开对应账单，手动触发想要演练的结果。Sandbox 不会按定时器自动确认任何付款。
3. 在 PrestaShop 后台观察订单的状态历史与备注。
4. 将 **Mode** 切换为 Live。此前的一次一键连接已经同时配置好两套环境，无需重复设置。

Sandbox 付款背后没有真实区块链，因此不会记录交易哈希或区块浏览器链接。

## 回调与对账

回调控制器读取原始请求体和 `X-Webhook-Signature` 请求头（格式 `t={timestamp},v1={hmac_hex}`，采用常量时间比对的 HMAC-SHA256 签名验证，且在密钥轮换期间同时兼容新旧两个 secret），并返回真实 HTTP 状态码。事件 ID 会存入模块独立数据表，重复投递时直接确认应答，不会对同一订单重复生效。写入任何终态之前，模块都会从 Merchant API 回查账单，并与本地保存的快照再次校验。

```text
https://your-store.example/index.php?fc=module&module=paymos&controller=callback
```

对账机制用于在回调未能送达时兜底。它每次拉取过去 24 小时内最多 50 笔未终结账单，逐一通过同一套状态映射逻辑处理，反向校验、金额校验与防回退保护同样生效。触发方式有两种：

- 通过 HTTP 访问配置页显示的带令牌 URL——定时任务应使用此地址，因为 PrestaShop 9 禁止直接访问 `modules/` 目录下的 `.php` 文件，cron 直接请求脚本会收到 403；
- 通过命令行执行 `php modules/paymos/cron/reconcile.php`，无需令牌。

每次对账运行除了未结账单外，还会同步检查处理中的 Paymos 退款。

## 退款

模块通过 Paymos Merchant API（`/v1/refunds`）支持原生退款，提供两种由服务端计算的模式：`full`（全额账单退款）和 `overpayment`（退还 `paid_over` 账单的超额付款部分）。退款会作为一笔新的出站链上转账，严格退回 Paymos 在已付账单上记录并验证的 `first_sender` 地址——无需手动填写收款钱包地址，也不支持任意金额的部分退款。

在 PrestaShop 后台，具备 **Orders（订单）** 编辑权限的员工可通过以下两种入口发起退款：

- 订单详情页中的 **Paymos refunds** 面板（`displayAdminOrderMain`，也可在 **Module Manager → Paymos → Configure** 按订单 ID 进入），提供 **Refund in full** 和 **Refund overpayment** 操作，并展示经过验证的 `first_sender` 地址与操作历史；
- PrestaShop 原生的整单全额退款流程（`paymosNativeRefund`）。

退款单（Order slip）和买家退款通知邮件会暂缓生成，直至 Paymos 退款进入 `completed` 状态并通过回调控制器或 `reconcile` 接口 / CLI 脚本向 Merchant API 验证确认。在 Sandbox 模式下，可模拟退款终态（`completed`、`failed`、`cancelled`）以完成端到端测试。

## 故障排查

**一键连接拒绝 HTTP 店铺 URL。** 请启用 `PS_SSL_ENABLED` 并确保店面确实通过 TLS 提供访问；模块会在错误提示中明确指出该设置，不会静默失败。

**订单一直停留在 Awaiting Paymos payment。** 请从公网外部请求上方回调 URL——它必须在无 HTTP 认证且无重定向的情况下正常响应，因为系统不会跟随重定向。随后查看 PrestaShop 日志中的 `PaymosPrestaShop` 条目。若店面曾短暂不可达，运行一次对账 URL 即可追平订单状态。

**订单进入 manual review（人工审核）。** 原因有两种，订单备注会明确区分。其一是账单创建后到付款入账前订单总额发生了变化，备注会列出快照金额、当前总额以及事件中的金额；其二是旧的 Paymos 账单已经支付、仍可支付或无法读取时，订单总额、模式或项目发生了改动，此时备注以 `"Paymos payment needs manual review."` 开头。第二种情况不会签发新账单，并会提示买家联系你，请先在 Paymos 控制台核查旧账单。两种情况均需人工结案。

**结账页未显示 Paymos。** 当购物车币种不在模块允许的币种列表中，或当前激活环境尚未完成连接时，Paymos 会自动隐藏。请先检查币种限制，再核对连接状态面板。

- [文档](https://paymos.io/zh-hans/docs/cms-prestashop)
- [源码](https://github.com/paymos-labs/prestashop)
- [更新日志](CHANGELOG.md)
- [技术支持](mailto:support@paymos.io)
