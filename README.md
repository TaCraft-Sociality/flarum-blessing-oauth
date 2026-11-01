# Blessing blessing oauth

用 Blessing Skin (皮肤站) 账号登录 Flarum 2 论坛。

用户点击登录页面的「皮肤站登录」按钮，完成 Blessing Skin 授权后自动创建或进入论坛账号，不需要另外填写邮箱和密码。

## 安装

运行 Composer 进行安装：

```bash
composer require tacraft-sociality/flarum-blessing-oauth
```

## 配置

在论坛后台的插件设置中填写以下信息：

配置项 | 示例 | 说明
--- | --- | ---
Blessing Skin 站点地址 | `https://example.com/` | 皮肤站的根 URL
OAuth Application ID | `1` | 在皮肤站中创建的 OAuth Client ID（整数）
OAuth Application Secret | `abcdefghij...` | 在皮肤站中创建 OAuth 客户端后生成的 Client Secret
显示名称 | Blessing Skin | 账号设置面板中显示的名称（例如：`TcsSkin` 或 `某某皮肤站`）；留空默认为 "Blessing Skin"
登录按钮文本 | 使用 Blessing Skin 登录 | 自定义登录按钮上显示的文字
登录按钮图标 | `far fa-id-card` | 登录按钮上使用的 FontAwesome 图标类名
图标图片 URL | `https://example.com/logo.png` | （可选）自定义图片 URL，设置后将替代 FontAwesome 图标
上传图标图片 | `logo.png` | （可选）直接上传本地图标（最大 2 MB）
自定义提示信息 | 自定义文本 | （可选）可覆盖前端的绑定状态、错误提示等文本，留空使用内置翻译

> **提示**：在 Blessing Skin 中创建 OAuth 客户端时，请将回调地址设置为：
> `https://你的论坛域名/blessing-oauth/callback`

## 功能特性

- **自动绑定与登录**：点击登录按钮跳转授权，授权完成后已注册用户直接登录并返回原页面；新用户自动填入邮箱和昵称并弹出注册框。
- **账号关联管理**：已登录用户可在「安全设置」中查看皮肤站账号绑定状态、绑定新账号或解除绑定。
- **版本兼容**：同时兼容 Blessing Skin 原生 Passport API（`/api/user`）与 `blessing-skin-auth-service` 插件（`/oauth/userinfo`）。

## 更新

覆盖插件目录源码或更新版本后执行：

```bash
composer update tacraft-sociality/flarum-blessing-oauth
php flarum cache:clear
```

如前端代码（`js/` 目录）有修改，需重新构建：

```bash
cd js
yarn install
yarn build
```

## 依赖

- Flarum `^2.0` (`flarum/core ^2.0`)
- PHP `^8.3`
- 支持 OAuth 的 Blessing Skin 皮肤站站点

## 许可

MIT