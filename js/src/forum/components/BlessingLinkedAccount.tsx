import app from 'flarum/forum/app'
import Component from 'flarum/common/Component'
import FieldSet from 'flarum/common/components/FieldSet'
import Button from 'flarum/common/components/Button'
import LoadingIndicator from 'flarum/common/components/LoadingIndicator'
import humanTime from 'flarum/common/helpers/humanTime'
import extractText from 'flarum/common/utils/extractText'
import type User from 'flarum/common/models/User'
import type Mithril from 'mithril'

import blessingIcon from './BlessingIcon'

const PREFIX = 'blessing-oauth-client.forum.link'
const LINK_PATH = '/blessing-oauth/callback'
const LINK_STATUS_ENDPOINT = '/blessing-oauth/link'
const ENABLED_ATTRIBUTE = 'blessing-oauth-client.enabled'
const PROVIDER_NAME_ATTRIBUTE = 'blessing-oauth-client.providerName'

export interface IBlessingLinkStatus {
  linked: boolean
  identifier: string | null
  createdAt: string | null
  lastLoginAt: string | null
}

interface IAttrs {
  user: User
}

interface IState {
  loading: boolean
  failed: boolean
  unlinking: boolean
  status: IBlessingLinkStatus | null
}

/**
 * The provider name configured in the admin settings, e.g. "Blessing Skin".
 */
function providerName(): string {
  return app.forum.attribute<string>(PROVIDER_NAME_ATTRIBUTE) || 'Blessing Skin'
}

/**
 * Translate a link-panel string with the configured provider name.
 */
function trans(
  key: string,
  params: Record<string, unknown> = {},
): Mithril.Children {
  return app.translator.trans(key, { provider: providerName(), ...params })
}

/**
 * Shows whether the current user has linked a provider account, and lets them
 * link or unlink one from their security settings.
 */
export default class BlessingLinkedAccount extends Component<IAttrs, IState> {
  state: IState = {
    loading: true,
    failed: false,
    unlinking: false,
    status: null,
  }

  oninit(vnode: Mithril.Vnode<IAttrs, this>): void {
    super.oninit(vnode)

    void this.load()
  }

  view(): Mithril.Children {
    return (
      <FieldSet
        className="BlessingOAuth-linkedAccount"
        label={trans(`${PREFIX}.heading`)}
      >
        <p className="helpText">{trans(`${PREFIX}.help`)}</p>
        {this.state.loading ? (
          <LoadingIndicator />
        ) : this.state.failed ? (
          this.errorView()
        ) : (
          this.accountView()
        )}
      </FieldSet>
    )
  }

  private errorView(): Mithril.Children {
    return (
      <div className="BlessingOAuth-Account-errors">
        <p className="helpText">{trans(`${PREFIX}.load_failed`)}</p>
        <Button className="Button" onclick={() => this.load()}>
          {trans(`${PREFIX}.retry`)}
        </Button>
      </div>
    )
  }

  private accountView(): Mithril.Children {
    const linked = this.state.status?.linked === true

    return (
      <div
        className={`BlessingOAuth-Account ${
          linked
            ? 'BlessingOAuth-Account--linked'
            : 'BlessingOAuth-Account--unlinked'
        }`}
      >
        <div className="BlessingOAuth-Account-icon">
          {blessingIcon('BlessingOAuth-icon BlessingOAuth-Account-logo')}
        </div>
        <div className="BlessingOAuth-Account-info">
          <span className="BlessingOAuth-Account-name">
            {trans(`${PREFIX}.name`)}
          </span>
          <span className="BlessingOAuth-Account-status">
            {trans(`${PREFIX}.${linked ? 'status_linked' : 'status_unlinked'}`)}
          </span>
          {linked ? this.linkedMeta() : null}
        </div>
        <div className="BlessingOAuth-Account-actions">
          {linked ? this.linkedActions() : this.unlinkedActions()}
        </div>
      </div>
    )
  }

  private linkedMeta(): Mithril.Children {
    const status = this.state.status
    const identifier = status?.identifier
    const createdAt = status?.createdAt

    if (!identifier && !createdAt) {
      return null
    }

    return (
      <span className="BlessingOAuth-Account-meta">
        {identifier ? trans(`${PREFIX}.identifier`, { identifier }) : null}
        {identifier && createdAt ? ' · ' : null}
        {createdAt
          ? trans(`${PREFIX}.linked_at`, {
              time: humanTime(new Date(createdAt)),
            })
          : null}
      </span>
    )
  }

  private unlinkedActions(): Mithril.Children {
    return (
      <Button
        className="Button BlessingOAuth-Account-bind"
        onclick={() => this.startLink()}
      >
        {trans(`${PREFIX}.bind_button`)}
      </Button>
    )
  }

  private linkedActions(): Mithril.Children {
    return [
      <Button
        className="Button"
        onclick={() => this.startLink()}
        disabled={this.state.unlinking}
      >
        {trans(`${PREFIX}.rebind_button`)}
      </Button>,
      <Button
        className="Button"
        onclick={() => this.unlink()}
        loading={this.state.unlinking}
      >
        {trans(`${PREFIX}.unbind_button`)}
      </Button>,
    ]
  }

  private startLink(): void {
    const baseUrl = String(
      app.forum.attribute<string>('baseUrl') ?? '',
    ).replace(/\/+$/, '')
    const returnTo = `${window.location.pathname}${window.location.search}`

    window.location.href = `${baseUrl}${LINK_PATH}?link=1&returnTo=${encodeURIComponent(
      returnTo,
    )}`
  }

  private async unlink(): Promise<void> {
    if (!confirm(extractText(trans(`${PREFIX}.unlink_confirm`)))) {
      return
    }

    this.state.unlinking = true
    m.redraw()

    try {
      await app.request({
        method: 'DELETE',
        url: `${app.forum.attribute<string>('apiUrl')}${LINK_STATUS_ENDPOINT}`,
      })

      this.state.status = {
        linked: false,
        identifier: null,
        createdAt: null,
        lastLoginAt: null,
      }

      app.alerts.show({ type: 'success' }, trans(`${PREFIX}.unlinked_success`))
    } catch {
      app.alerts.show({ type: 'error' }, trans(`${PREFIX}.unlink_failed`))
    } finally {
      this.state.unlinking = false
      m.redraw()
    }
  }

  private async load(): Promise<void> {
    if (!app.forum.attribute<boolean>(ENABLED_ATTRIBUTE)) {
      this.state.loading = false
      this.state.failed = false

      return
    }

    this.state.loading = true
    this.state.failed = false
    m.redraw()

    try {
      this.state.status = await app.request<IBlessingLinkStatus>({
        method: 'GET',
        url: `${app.forum.attribute<string>('apiUrl')}${LINK_STATUS_ENDPOINT}`,
      })
    } catch {
      this.state.failed = true
    } finally {
      this.state.loading = false
      m.redraw()
    }
  }
}
