/**
 * Media picker for the Screenly Cast logo setting.
 *
 * Hand-written and shipped as-is: it needs no bundling, so it deliberately does
 * not go through the signage build. Plain DOM rather than jQuery, wp.media is
 * the only dependency, provided by wp_enqueue_media().
 *
 * @package ScreenlyCast
 */

/* global wp, screenlyCastAdmin */

;(() => {
  const PREVIEW_MAX_WIDTH = '220px'

  function init() {
    const field = document.querySelector('.screenly-cast-logo-field')
    if (!field) {
      return
    }

    const input = field.querySelector('input[type="hidden"]')
    const preview = field.querySelector('.screenly-cast-logo-field__preview')
    const chooseButton = field.querySelector('.screenly-cast-logo-field__choose')
    const removeButton = field.querySelector('.screenly-cast-logo-field__remove')

    if (!input || !preview || !chooseButton || !removeButton) {
      return
    }

    let frame = null

    chooseButton.addEventListener('click', () => {
      if (!frame) {
        frame = wp.media({
          title: screenlyCastAdmin.frameTitle,
          button: { text: screenlyCastAdmin.frameButton },
          library: { type: 'image' },
          multiple: false
        })

        frame.on('select', () => {
          const attachment = frame.state().get('selection').first().toJSON()

          input.value = String(attachment.id)

          const source = attachment.sizes?.medium?.url ?? attachment.url

          const image = document.createElement('img')
          image.src = source
          image.alt = ''
          image.style.maxWidth = PREVIEW_MAX_WIDTH
          image.style.height = 'auto'

          preview.replaceChildren(image)
          removeButton.removeAttribute('hidden')
        })
      }

      frame.open()
    })

    removeButton.addEventListener('click', () => {
      input.value = '0'
      preview.replaceChildren()
      removeButton.setAttribute('hidden', 'hidden')
    })
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init)
  } else {
    init()
  }
})()
