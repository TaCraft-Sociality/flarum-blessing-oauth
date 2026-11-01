import app from 'flarum/forum/app'
import Icon from 'flarum/common/components/Icon'
import type Mithril from 'mithril'

/**
 * Builds the Blessing Skin glyph (or the uploaded image) as a vnode.
 *
 * This is a plain helper that returns a vnode, not a component: Flarum 2 runs
 * on Mithril 2, where a component function must return a `{ view }` closure
 * rather than a vnode, so `<BlessingIcon />` would crash during rendering.
 */
export default function blessingIcon(
  className = 'BlessingOAuth-icon',
): Mithril.Vnode {
  const image = app.forum.attribute<string>(
    'blessing-oauth-client.loginIconImage',
  )

  if (image) {
    return (
      <img
        className={`${className} BlessingOAuth-iconImage`}
        src={image}
        alt=""
      />
    )
  }

  return (
    <Icon
      name={
        app.forum.attribute<string>('blessing-oauth-client.loginIcon') ||
        'far fa-id-card'
      }
      className={className}
    />
  )
}
