/**
 * @file
 * Glossary Tooltip behavior: hover, focus and tap interactions.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.glossaryTooltip = {
    attach: function (context) {
      once('glossary-tooltip', '.glossary-term', context).forEach(initTerm);
    },
  };

  function initTerm(term) {
    const tooltip = term.querySelector(':scope > .glossary-tooltip');
    if (!tooltip) {
      return;
    }

    let isOpen = false;

    function show() {
      positionTooltip(term, tooltip);
      term.classList.add('is-open');
      term.setAttribute('aria-expanded', 'true');
      isOpen = true;
    }

    function hide() {
      term.classList.remove('is-open');
      term.setAttribute('aria-expanded', 'false');
      isOpen = false;
    }

    term.addEventListener('mouseenter', show);
    term.addEventListener('mouseleave', hide);
    term.addEventListener('focusin', show);
    term.addEventListener('focusout', function (event) {
      if (!term.contains(event.relatedTarget)) {
        hide();
      }
    });

    // Tap toggles the tooltip on touch devices; the "Read more" link inside
    // stays a normal link.
    term.addEventListener('click', function (event) {
      if (event.target.closest('a')) {
        return;
      }
      event.preventDefault();
      if (isOpen) {
        hide();
      } else {
        show();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && isOpen) {
        hide();
        term.focus();
      }
    });

    document.addEventListener('click', function (event) {
      if (isOpen && !term.contains(event.target)) {
        hide();
      }
    });
  }

  /**
   * Places the tooltip above or below the term depending on free space.
   */
  function positionTooltip(term, tooltip) {
    tooltip.classList.remove('glossary-tooltip--above', 'glossary-tooltip--below');

    // Make the tooltip measurable without flashing it on screen.
    term.classList.add('is-measuring');
    const termRect = term.getBoundingClientRect();
    const tooltipHeight = tooltip.getBoundingClientRect().height;
    term.classList.remove('is-measuring');

    const spaceBelow = window.innerHeight - termRect.bottom;
    const spaceAbove = termRect.top;
    const needed = tooltipHeight + 12;

    if (spaceBelow >= needed || spaceBelow >= spaceAbove) {
      tooltip.classList.add('glossary-tooltip--below');
    } else {
      tooltip.classList.add('glossary-tooltip--above');
    }
  }
})(Drupal, once);
