import app from 'flarum/forum/app'
import { extend } from 'flarum/common/extend'
import Button from 'flarum/common/components/Button'
import LogInButtons from 'flarum/forum/components/LogInButtons'

import type ItemList from 'flarum/common/utils/ItemList'
import type User from 'flarum/common/models/User'
import type Mithril from 'mithril'

import BlessingLinkedAccount from './components/BlessingLinkedAccount'

const EXTENSION_ID = 'tacraft-sociality-blessing-oauth'
const LOGIN_PATH = '/blessing-oauth/callback'

/**
 * The provider name configured in the admin settings, e.g. "Blessing Skin".
 */
function providerName(): string {
  return (
    app.forum.attribute<string>('blessing-oauth-client.providerName') ||
    'Blessing Skin'
  )
}

/**
 * Use the admin-provided text when present, otherwise fall back to the
 * bundled translation, filling in the configured provider name.
 */
function customMessage(attribute: string, fallback: string): Mithril.Children {
  const custom = app.forum.attribute<string>(attribute)

  return custom?.trim()
    ? custom
    : app.translator.trans(fallback, { provider: providerName() })
}

/**
 * Marker appended to the `returnTo` path so that, when several OAuth
 * extensions are installed, only this extension reacts to the registration
 * token Flarum appends to the URL after an OAuth callback.
 */
const SOURCE_PARAM = 'blessing_oauth'
const SOURCE_VALUE = 'blessing'

/**
 * Remove a query string flag from the current URL and return its value.
 *
 * The URL is rewritten without the flag so it does not stay in the browser
 * history or get bookmarked.
 */
function consumeQueryFlag(name: string): string | null {
  const params = new URLSearchParams(window.location.search)
  const value = params.get(name)

  if (value === null) return null

  params.delete(name)

  const query = params.toString()

  window.history.replaceState(
    {},
    '',
    `${window.location.pathname}${query ? `?${query}` : ''}${window.location.hash}`,
  )

  return value
}

/**
 * Build the URL that starts the OAuth flow, returning to the current page
 * once the user is logged in.
 */
function loginUrl(): string {
  const baseUrl = app.forum.attribute<string>('baseUrl')
  const returnTo = encodeURIComponent(
    window.location.pathname + window.location.search,
  )

  return `${baseUrl}${LOGIN_PATH}?returnTo=${returnTo}`
}

app.initializers.add(EXTENSION_ID, () => {
  extend(
    LogInButtons.prototype,
    'items',
    function (items: ItemList<Mithril.Children>) {
      const iconImage = app.forum.attribute<string>(
        'blessing-oauth-client.loginIconImage',
      )

      items.add(
        EXTENSION_ID,
        <Button
          className="Button LogInButton LogInButton--blessing"
          icon={
            iconImage
              ? undefined
              : app.forum.attribute<string>('blessing-oauth-client.loginIcon')
          }
          onclick={() => window.location.assign(loginUrl())}
        >
          {iconImage ? (
            <img
              className="LogInButton-logo"
              src={iconImage}
              alt=""
              aria-hidden="true"
              style={{
                width: '1em',
                height: '1em',
                marginRight: '0.5em',
                objectFit: 'contain',
                verticalAlign: '-0.125em',
              }}
            />
          ) : null}
          {app.forum.attribute<string>('blessing-oauth-client.loginTitle')}
        </Button>,
        0,
      )
    },
  )

  // Add the link/unlink panel to the user's own security settings, right
  // below the QQ OAuth section (which uses priority 100).
  extend(
    'flarum/forum/components/UserSecurityPage',
    'settingsItems',
    function (items: ItemList<Mithril.Children>) {
      const user = (this as unknown as { user?: User }).user

      if (
        !user ||
        user.id() !== app.session.user?.id() ||
        !app.forum.attribute<boolean>('blessing-oauth-client.enabled')
      ) {
        return
      }

      items.add(
        'blessingOAuthLinkedAccount',
        <BlessingLinkedAccount user={user} />,
        99,
      )
    },
  )

  const source = consumeQueryFlag(SOURCE_PARAM)
  if (source === SOURCE_VALUE) {
    if (consumeQueryFlag('_flarum_linked') === SOURCE_VALUE) {
      app.alerts.show(
        { type: 'success' },
        customMessage(
          'blessing-oauth-client.messageLinked',
          'blessing-oauth-client.forum.linked',
        ),
      )
    } else {
      const authToken = consumeQueryFlag('_flarum_auth')

      if (authToken) {
        // Wait for the modal manager to be mounted, then pre-fill the sign up
        // modal with the data the provider returned.
        setTimeout(async () => {
          let attrs: Record<string, unknown> = { token: authToken }

          try {
            const data = await app.request<{
              username?: string
              email?: string
              provided?: string[]
            }>({
              method: 'POST',
              url: `${app.forum.attribute<string>('apiUrl')}/registration-token`,
              body: { token: authToken },
            })

            attrs = {
              token: authToken,
              username: data?.username ?? '',
              email: data?.email ?? '',
              provided: data?.provided ?? [],
            }
          } catch {
            // The token may have already been used; the modal will show empty fields.
          }

          app.modal.show(
            () => import('flarum/forum/components/SignUpModal'),
            attrs,
          )
        }, 0)
      }
    }
  }

  const errorReason = consumeQueryFlag('blessing_oauth_error')
  const errorHint = consumeQueryFlag('blessing_oauth_hint')

  if (errorReason !== null) {
    const errorMessages: Record<string, [string, string]> = {
      state: [
        'blessing-oauth-client.messageErrorState',
        'blessing-oauth-client.forum.login-error-state',
      ],
      provider: [
        'blessing-oauth-client.messageErrorProvider',
        'blessing-oauth-client.forum.login-error-provider',
      ],
      token: [
        'blessing-oauth-client.messageErrorToken',
        'blessing-oauth-client.forum.login-error-token',
      ],
      userinfo: [
        'blessing-oauth-client.messageErrorUserinfo',
        'blessing-oauth-client.forum.login-error-userinfo',
      ],
      identifier: [
        'blessing-oauth-client.messageErrorIdentifier',
        'blessing-oauth-client.forum.login-error-identifier',
      ],
      already_linked: [
        'blessing-oauth-client.messageErrorAlreadyLinked',
        'blessing-oauth-client.forum.login-error-already-linked',
      ],
      login_required: [
        'blessing-oauth-client.messageErrorLoginRequired',
        'blessing-oauth-client.forum.login-error-login-required',
      ],
    }

    const [attribute, fallback] = errorMessages[errorReason] ?? [
      'blessing-oauth-client.messageError',
      'blessing-oauth-client.forum.login-error',
    ]

    const message = customMessage(attribute, fallback)

    app.alerts.show(
      { type: 'error' },
      errorHint ? [message, ' ', `(${errorHint})`] : message,
    )
  }
})
