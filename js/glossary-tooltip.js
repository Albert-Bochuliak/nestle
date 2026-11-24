/**
 * @file
 * Glossary Tooltip behavior: hover, focus and tap interactions.
 */
(function (Drupal, once) {
  'use strict';

  // The currently open term: { trigger, hide } or null. A single pair of
  // delegated document listeners handles Escape and outside clicks for
  // all terms instead of registering listeners per term.
  let openTerm = null;
  let globalBound = false;
  // Unique tooltip IDs are assigned at init so repeated cached fragments
  // and AJAX-inserted content can never produce duplicate HTML IDs.
  let nextTooltipId = 0;

  Drupal.behaviors.glossaryTooltip = {
    attach: function (context) {
      once('glossary-tooltip', '.glossary-term', context).forEach(initTerm);
      if (!globalBound) {
        globalBound = true;
        document.addEventListener('keydown', function (event) {
          if (event.key === 'Escape' && openTerm) {
            openTerm.hide();
            openTerm.trigger.focus();
          }
        });
        document.addEventListener('click', function (event) {
          if (openTerm && !openTerm.term.contains(event.target)) {
            openTerm.hide();
          }
        });
      }
    },
  };

  function initTerm(term) {
    const trigger = term.querySelector(':scope > .glossary-term__trigger');
    const tooltip = term.querySelector(':scope > .glossary-tooltip');
    if (!trigger || !tooltip) {
      return;
    }

    tooltip.id = 'glossary-tooltip-' + (++nextTooltipId);
    trigger.setAttribute('aria-controls', tooltip.id);

    let isOpen = false;

    function show() {
      if (openTerm && openTerm.term !== term) {
        openTerm.hide();
      }
      positionTooltip(term, tooltip);
      term.classList.add('is-open');
      trigger.setAttribute('aria-expanded', 'true');
      isOpen = true;
      openTerm = { term: term, trigger: trigger, hide: hide };
    }

    function hide() {
      term.classList.remove('is-open');
      trigger.setAttribute('aria-expanded', 'false');
      isOpen = false;
      if (openTerm && openTerm.term === term) {
        openTerm = null;
      }
    }

    term.addEventListener('mouseenter', show);
    term.addEventListener('mouseleave', hide);
    term.addEventListener('focusin', show);
    term.addEventListener('focusout', function (event) {
      if (!term.contains(event.relatedTarget)) {
        hide();
      }
    });

    // The trigger is a button: click also covers Enter and Space. Tapping
    // toggles on touch devices; the "Read more" link stays a normal link.
    trigger.addEventListener('click', function () {
      if (isOpen) {
        hide();
      } else {
        show();
      }
    });
  }

  /**
   * Places the tooltip above or below the term depending on free space,
   * and clamps it horizontally to the surrounding text block.
   */
  function positionTooltip(term, tooltip) {
    tooltip.classList.remove('glossary-tooltip--above', 'glossary-tooltip--below');
    tooltip.style.marginLeft = '';
    tooltip.style.setProperty('--arrow-offset', '0px');

    // Make the tooltip measurable without flashing it on screen.
    term.classList.add('is-measuring');
    const termRect = term.getBoundingClientRect();
    const tooltipRect = tooltip.getBoundingClientRect();
    term.classList.remove('is-measuring');

    const spaceBelow = window.innerHeight - termRect.bottom;
    const spaceAbove = termRect.top;
    const needed = tooltipRect.height + 12;

    if (spaceBelow >= needed || spaceBelow >= spaceAbove) {
      tooltip.classList.add('glossary-tooltip--below');
    } else {
      tooltip.classList.add('glossary-tooltip--above');
    }

    // Keep the tooltip inside the surrounding text block (clamped to the
    // viewport): when it would overflow an edge, shift the box and
    // counter-shift the arrow so it still points at the term.
    const margin = 8;
    const parent = term.parentElement;
    const parentRect = parent ? parent.getBoundingClientRect() : null;

    const boundLeft = Math.max(margin, parentRect ? parentRect.left : -Infinity);
    const boundRight = Math.min(
      window.innerWidth - margin,
      parentRect ? parentRect.right : Infinity,
    );

    const shiftRight = Math.max(boundLeft - tooltipRect.left, 0);
    const shiftLeft = Math.min(boundRight - tooltipRect.right, 0);
    const offset = shiftRight !== 0 ? shiftRight : shiftLeft;

    if (offset !== 0) {
      tooltip.style.marginLeft = offset + 'px';
      tooltip.style.setProperty('--arrow-offset', -offset + 'px');
    }
  }
})(Drupal, once);
