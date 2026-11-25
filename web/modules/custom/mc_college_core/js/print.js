/**
 * @file
 * Print button for the transcript page.
 */
((Drupal, once) => {
  Drupal.behaviors.mcCollegePrint = {
    attach(context) {
      once('mc-print', '.mc-print', context).forEach((button) => {
        button.addEventListener('click', () => window.print());
      });
    },
  };
})(Drupal, once);
