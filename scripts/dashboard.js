document.addEventListener("DOMContentLoaded", function () {
  function updateAiRecommendationNumbers() {
    document.querySelectorAll(".ai-recommendation-item").forEach(function (item, index) {
      const numberSpan = item.querySelector(".ai-recommendation-header h3 > span");
      if (numberSpan) {
        numberSpan.textContent = `${index + 1}. `;
      }
    });
  }

  document.querySelectorAll(".ai-recommendation-item").forEach(function (item) {
    item.addEventListener("click", function () {
      item.classList.toggle("expanded");
    });
  });

  document.querySelectorAll(".delete-ai-recommendation").forEach(function (el) {
    el.addEventListener("click", function (e) {
      e.preventDefault();
      e.stopPropagation();

      const item = el.closest(".ai-recommendation-item");
      const id = item.getAttribute("data-id");

      fetch("endpoints/ai/delete_recommendation.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": window.csrfToken,
        },
        body: JSON.stringify({ id: id }),
      })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            item.remove();
            updateAiRecommendationNumbers();
            showSuccessMessage(translate("success"));
          } else {
            showErrorMessage(data.message || translate("failed_delete_ai_recommendation"));
          }
        })
        .catch(error => {
          console.error(error);
          showErrorMessage(translate("unknown_error"));
        });
    });
  });

});


// The subscription details popup's mark/unmark-paid buttons call these by
// name. subscriptions.js defines the same functions, but index.php never
// loads subscriptions.js, so they're missing here. The dashboard has no
// live subscription cards to patch in place, so on success we just reload
// the page to reflect the updated paid status and re-run the overdue /
// upcoming queries.
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
