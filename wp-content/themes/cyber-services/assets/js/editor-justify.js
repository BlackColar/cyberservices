(function (wp) {
  'use strict';

  const { BlockControls } = wp.blockEditor;
  const { ToolbarButton, ToolbarGroup } = wp.components;
  const { createHigherOrderComponent } = wp.compose;
  const { createElement, Fragment } = wp.element;
  const { addFilter } = wp.hooks;

  const justifyIcon = createElement(
    'svg',
    { width: 24, height: 24, viewBox: '0 0 24 24', 'aria-hidden': true, focusable: false },
    createElement('path', { d: 'M4 5.5h16V7H4V5.5Zm0 4h16V11H4V9.5Zm0 4h16V15H4v-1.5Zm0 4h16V19H4v-1.5Z' })
  );

  const withParagraphJustifyControl = createHigherOrderComponent((BlockEdit) => {
    return function ParagraphJustifyControl(props) {
      if (props.name !== 'core/paragraph') {
        return createElement(BlockEdit, props);
      }

      const isJustified = props.attributes.align === 'justify';

      return createElement(
        Fragment,
        null,
        createElement(BlockEdit, props),
        createElement(
          BlockControls,
          { group: 'block' },
          createElement(
            ToolbarGroup,
            null,
            createElement(ToolbarButton, {
              icon: justifyIcon,
              label: 'Căn đều hai bên',
              isPressed: isJustified,
              onClick: function () {
                props.setAttributes({ align: isJustified ? undefined : 'justify' });
              },
            })
          )
        )
      );
    };
  }, 'withParagraphJustifyControl');

  addFilter(
    'editor.BlockEdit',
    'cyber-services/paragraph-justify-control',
    withParagraphJustifyControl
  );
})(window.wp);
