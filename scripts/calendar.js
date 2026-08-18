function nextMonth(currentMonth, currentYear) {
  let nextMonth = currentMonth + 1;
  let nextYear = currentYear;
  if (nextMonth > 12) {
    nextMonth = 1;
    nextYear += 1;
  }
  window.location.href = `calendar.php?month=${nextMonth}&year=${nextYear}`;
}

function prevMonth(currentMonth, currentYear) {
  let prevMonth = currentMonth - 1;
  let prevYear = currentYear;
  if (prevMonth < 1) {
    prevMonth = 12;
    prevYear -= 1;
  }
  window.location.href = `calendar.php?month=${prevMonth}&year=${prevYear}`;
}

function currentMoth() {
    window.location.href = `calendar.php`;
}

function showExportPopup() {
  const host = window.location.href;
  const apiPath = "api/subscriptions/get_ical_feed.php";
  const apiKey = document.getElementById('apiKey').value;
  const queryParams = `?api_key=${apiKey}`;
  const fullUrl = host.replace('calendar.php', apiPath) + queryParams;
  document.getElementById('iCalendarUrl').value = fullUrl;
  document.getElementById('subscriptions_calendar').classList.add('is-open');
}

function closePopup() {
  document.getElementById('subscriptions_calendar').classList.remove('is-open');
}

function copyToClipboard() {
  const urlField = document.getElementById('iCalendarUrl');
  urlField.select();
  urlField.setSelectionRange(0, 99999); // For mobile devices
  navigator.clipboard.writeText(urlField.value)
      .then(() => {
          showSuccessMessage(translate('copied_to_clipboard'));
      })
      .catch(() => {
          showErrorMessage(translate('unknown_error'));
      });
}

// The subscription details popup's mark/unmark-paid buttons call these by
// name. subscriptions.js defines the same functions, but calendar.php never
// loads subscriptions.js, so they're missing here. calendar.php has no live
// subscription cards to patch in place, so on success we just reload the
// page to reflect the updated paid status.
function markAsPaid(event, id) {
  event.stopPropagation();
  event.preventDefault();

  fetch("endpoints/subscription/mark_paid.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": window.csrfToken,
    },
    body: JSON.stringify({ id: id }),
  })
    .then((response) => {
      if (!response.ok) {
        throw new Error(translate("network_response_error"));
      }
      return response.json();
    })
    .then((data) => {
      if (data.success) {
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch((error) => {
      showErrorMessage(error.message || translate("error"));
    });
}

function unmarkPaid(event, id) {
  event.stopPropagation();
  event.preventDefault();

  fetch("endpoints/subscription/unmark_paid.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": window.csrfToken,
    },
    body: JSON.stringify({ id: id }),
  })
    .then((response) => {
      if (!response.ok) {
        throw new Error(translate("network_response_error"));
      }
      return response.json();
    })
    .then((data) => {
      if (data.success) {
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate("error"));
      }
    })
    .catch((error) => {
      showErrorMessage(error.message || translate("error"));
    });
}