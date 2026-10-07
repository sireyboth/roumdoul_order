/*
 * Back office, branch form: "Use my current location".
 * Fills the latitude/longitude fields with this device's position. `set` is Filament's
 * $set for the form the button is in. Laptops guess their place from Wi-Fi and can be
 * far off, so a rough position gets a warning and the owner is asked to check the map.
 */
window.roUseMyLocation = function (set) {
  const notify = (title, body, status) => {
    if (window.FilamentNotification) {
      new window.FilamentNotification().title(title).body(body)[status]().send();
    } else {
      window.alert(title + '\n' + body);
    }
  };

  if (!window.isSecureContext || !navigator.geolocation) {
    notify('Location not available', 'The browser only shares location on a secure (https) page.', 'danger');
    return;
  }

  notify('Finding your location...', 'Allow location if the browser asks.', 'info');

  navigator.geolocation.getCurrentPosition(
    (position) => {
      const accuracy = Math.round(position.coords.accuracy);
      set('latitude', Number(position.coords.latitude.toFixed(7)), false, true);
      set('longitude', Number(position.coords.longitude.toFixed(7)), false, true);

      if (accuracy > 100) {
        notify(
          'Location set, but it is rough (about ' + accuracy + ' m)',
          'Computers guess their place from Wi-Fi. Tap "Check on map": if the pin is not on your shop, use a phone inside the shop or paste the Google Maps link instead.',
          'warning',
        );
      } else {
        notify('Location set (about ' + accuracy + ' m)', 'Tap "Check on map" to make sure the pin is on your shop, then save.', 'success');
      }
    },
    (error) =>
      notify(
        'Could not get your location',
        error.code === 1
          ? 'Location is blocked for this page. Allow it (lock icon next to the address), then try again.'
          : 'Turn on location on this device and try again, or paste the Google Maps link.',
        'danger',
      ),
    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
  );
};
