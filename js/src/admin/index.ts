import app from 'flarum/admin/app'

app.initializers.add('tacraft-sociality-blessing-oauth', () => {
  app.registry
    .for('tacraft-sociality-blessing-oauth')
    .registerSetting({
      label: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.base-url',
      ),
      setting: 'blessing-oauth-client.base_url',
      type: 'text',
      placeholder: 'https://example.com/',
    })
    .registerSetting({
      label: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.app-id',
      ),
      setting: 'blessing-oauth-client.app_id',
      type: 'text',
      placeholder: '123',
    })
    .registerSetting({
      label: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.app-secret',
      ),
      setting: 'blessing-oauth-client.app_secret',
      type: 'text',
      placeholder: 'abcdefghijABCDEFGHIJabcdefghijABCDEFGHIJ',
    })
    .registerSetting({
      label: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.button-title',
      ),
      setting: 'blessing-oauth-client.button_title',
      type: 'text',
      placeholder: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.button-title-placeholder',
      ),
    })
    .registerSetting({
      label: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.button-icon',
      ),
      setting: 'blessing-oauth-client.button_icon',
      type: 'text',
      placeholder: 'far fa-id-card',
    })
    .registerSetting({
      label: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.button-icon-image',
      ),
      help: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.button-icon-image-help',
      ),
      setting: 'blessing-oauth-client.button_icon_image',
      type: 'text',
      placeholder: 'https://example.com/logo.png',
    })
    .registerSetting({
      label: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.button-icon-upload',
      ),
      help: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.button-icon-upload-help',
      ),
      setting: 'blessing-oauth-client.button_icon_upload_url',
      type: 'image-upload',
      name: 'icon',
      routePath: 'blessing-oauth/icon',
      url: () => {
        const url =
          app.data.settings['blessing-oauth-client.button_icon_upload_url']

        if (!url) return null

        return url.startsWith('http')
          ? url
          : app.forum.attribute<string>('baseUrl').replace(/\/$/, '') + url
      },
    })
    .registerSetting({
      label: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.provider-name',
      ),
      help: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.provider-name-help',
      ),
      setting: 'blessing-oauth-client.provider_name',
      type: 'text',
      placeholder: 'Blessing Skin',
    })
    .registerSetting({
      label: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.verify-ssl',
      ),
      help: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.verify-ssl-help',
      ),
      setting: 'blessing-oauth-client.verify_ssl',
      type: 'bool',
    })

  const messageFields: Array<[string, string]> = [
    ['message-linked', 'message_linked'],
    ['message-error', 'message_error'],
    ['message-error-state', 'message_error_state'],
    ['message-error-provider', 'message_error_provider'],
    ['message-error-token', 'message_error_token'],
    ['message-error-userinfo', 'message_error_userinfo'],
    ['message-error-identifier', 'message_error_identifier'],
  ]

  messageFields.forEach(([field, setting]) => {
    app.registry.for('tacraft-sociality-blessing-oauth').registerSetting({
      label: app.translator.trans(
        `blessing-oauth-client.admin.popup.field.${field}`,
      ),
      help: app.translator.trans(
        'blessing-oauth-client.admin.popup.field.message-help',
      ),
      setting: `blessing-oauth-client.${setting}`,
      type: 'textarea',
    })
  })
})
