<?php
require_once 'includes/header.php';

function getPaymentCycleDays($cycle, $frequency)
{
  switch ($cycle) {
    case 1: return 1 * $frequency;
    case 2: return 7 * $frequency;
    case 3: return 30 * $frequency;
    case 4: return 365 * $frequency;
    default: return 0;
  }
}

function isPaidThisCycle($paidAt, $cycle, $frequency, $nextPayment)
{
  if (!$paidAt || $cycle === 5) {
    return false;
  }

  $paidDate = new DateTime($paidAt);
  $nextPaymentDate = new DateTime($nextPayment);
  $currentDate = new DateTime((new DateTime('now'))->format('Y-m-d'));
  $paymentCycleDays = getPaymentCycleDays($cycle, $frequency);

  if ($paymentCycleDays <= 0) {
    return false;
  }

  if ($currentDate > $nextPaymentDate) {
    // Overdue: accept paid_at from one cycle before through one cycle after
    // next_payment — not just on/after it — so an early payment (made a
    // few days before the due date) doesn't stop counting the moment the
    // due date itself passes.
    $cycleEnd = clone $nextPaymentDate;
    $cycleEnd->modify('+' . $paymentCycleDays . ' days');
    $cycleStart = clone $nextPaymentDate;
    $cycleStart->modify('-' . $paymentCycleDays . ' days');
    return $paidDate >= $cycleStart && $paidDate <= $cycleEnd;
  }

  // Normal: walk back from next_payment to find the cycle containing today
  $daysUntilNextPayment = $currentDate->diff($nextPaymentDate)->days;
  $cyclesBack = max(1, (int) ceil($daysUntilNextPayment / $paymentCycleDays));
  $cycleStart = clone $nextPaymentDate;
  $cycleStart->modify('-' . ($cyclesBack * $paymentCycleDays) . ' days');
  return $paidDate >= $cycleStart && $paidDate <= $nextPaymentDate;
}

function getPriceConverted($price, $currency, $database, $userId)
{
  $query = "SELECT rate FROM currencies WHERE id = :currency AND user_id = :userId";
  $stmt = $database->prepare($query);
  $stmt->bindParam(':currency', $currency, SQLITE3_INTEGER);
  $stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
  $result = $stmt->execute();

  $exchangeRate = $result->fetchArray(SQLITE3_ASSOC);
  if ($exchangeRate === false) {
    return $price;
  } else {
    $fromRate = $exchangeRate['rate'];
    return $price / $fromRate;
  }
}

// Get budget from user table
$query = "SELECT budget FROM user WHERE id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
$budget = $row['budget'] ?? 0;

$currentMonth = date('m');
$currentYear = date('Y');
$sameAsCurrent = false;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['month']) && isset($_GET['year'])) {
  // Don't allow viewing past months
  $selectedMonth = str_pad($_GET['month'], 2, '0', STR_PAD_LEFT);
  $selectedYear = $_GET['year'];

  $selectedTimestamp = strtotime($selectedYear . '-' . $selectedMonth . '-01');
  $currentTimestamp = strtotime($currentYear . '-' . $currentMonth . '-01');

  if ($selectedTimestamp < $currentTimestamp) {
    $calendarMonth = $currentMonth;
    $calendarYear = $currentYear;
  } else {
    $calendarMonth = $selectedMonth;
    $calendarYear = $selectedYear;
  }

  if ($calendarMonth == $currentMonth && $calendarYear == $currentYear) {
    $sameAsCurrent = true;
  }
} else {
  $calendarMonth = $currentMonth;
  $calendarYear = $currentYear;
  $sameAsCurrent = true;
}

$currenciesInUse = [];
$numberOfSubscriptionsToPayThisMonth = 0;
$totalCostThisMonth = 0;
$amountDueThisMonth = 0;

$query = "SELECT * FROM subscriptions WHERE user_id = :user_id AND inactive = 0";
$stmt = $db->prepare($query);
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$subscriptions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
  $subscriptions[] = $row;
  $currenciesInUse[] = $row['currency_id'];
}

$currenciesInUse = array_unique($currenciesInUse);
$usesMultipleCurrencies = count($currenciesInUse) > 1;

$showCantConverErrorMessage = false;
if ($usesMultipleCurrencies) {
  $query = "SELECT api_key FROM fixer WHERE user_id = :userId";
  $stmt = $db->prepare($query);
  $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
  $result = $stmt->execute();
  if ($result->fetchArray(SQLITE3_ASSOC) === false) {
    $showCantConverErrorMessage = true;
  }
}

// Get code of main currency to display on statistics
$query = "SELECT c.code
          FROM currencies c
          INNER JOIN user u ON c.id = u.main_currency
          WHERE u.id = :userId";
$stmt = $db->prepare($query);
$stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$row = $result->fetchArray(SQLITE3_ASSOC);
$code = $row['code'];

$yearsToLoad = $calendarYear - $currentYear + 1;
$weekStartsSunday = !empty($settings['week_starts_sunday']);
$weekDays = [
  ['key' => 'mon', 'offset' => 0],
  ['key' => 'tue', 'offset' => 1],
  ['key' => 'wed', 'offset' => 2],
  ['key' => 'thu', 'offset' => 3],
  ['key' => 'fri', 'offset' => 4],
  ['key' => 'sat', 'offset' => 5],
  ['key' => 'sun', 'offset' => 6],
];

if ($weekStartsSunday) {
  $weekDays = [
    ['key' => 'sun', 'offset' => 6],
    ['key' => 'mon', 'offset' => 0],
    ['key' => 'tue', 'offset' => 1],
    ['key' => 'wed', 'offset' => 2],
    ['key' => 'thu', 'offset' => 3],
    ['key' => 'fri', 'offset' => 4],
    ['key' => 'sat', 'offset' => 5],
  ];
}
?>

<section class="contain">
  <?php
  if ($showCantConverErrorMessage) {
    ?>
    <div class="error-box">
      <div class="error-message">
        <i class="fa-solid fa-exclamation-circle"></i>
        <?= translate('cant_convert_currency', $i18n) ?>
      </div>
    </div>
    <?php
  }
  ?>
  <div class="split-header">
    <div class="calendar-title">
      <h2><?= translate('month-' . $calendarMonth, $i18n) ?> <?= $calendarYear ?></h2>
      <div class="calendar-nav">
        <button class="button secondary-button" id="prev"
          onclick="prevMonth(<?= $calendarMonth ?>, <?= $calendarYear ?>)" <?= $sameAsCurrent ? 'disabled' : '' ?>>
          <i class="fa-solid fa-chevron-left"></i>
        </button>
        <button class="button secondary-button" id="next"
          onclick="nextMonth(<?= $calendarMonth ?>, <?= $calendarYear ?>)">
          <i class="fa-solid fa-chevron-right"></i>
        </button>
        <?php
        if (!$sameAsCurrent) {
          ?>
          <button class="button secondary-button" onClick="currentMoth()" title="<?= translate('reset', $i18n) ?>">
            <i class="fa-solid fa-calendar-day"></i>
          </button>
          <?php
        }
        ?>
      </div>
    </div>
    <button class="button secondary-button export-ical" onClick="showExportPopup()"
      title="<?= translate('export_icalendar', $i18n) ?>" aria-label="<?= translate('export_icalendar', $i18n) ?>">
      <?php require_once 'images/siteicons/svg/export_ical.php'; ?>
    </button>
    <div id="subscriptions_calendar" class="subscription-modal">
        <div class="modal-header">
            <h3><?= translate('export_icalendar', $i18n) ?></h3>
            <span class="fa-solid fa-xmark close-modal" onclick="closePopup()"></span>
        </div>
        <div class="form-group-inline">
            <input id="iCalendarUrl" type="text" value="" readonly>
            <input type="hidden" id="apiKey" value="<?= $userData['api_key'] ?>">
            <button onclick="copyToClipboard()" class="button tiny"> <?= translate('copy_to_clipboard', $i18n) ?> </button>
        </div>
    </div>
  </div>
  <div>
    <?php
    $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $calendarMonth, $calendarYear);
    $firstDay = mktime(0, 0, 0, $calendarMonth, 1, $calendarYear);
    $firstDayOfWeek = date('N', $firstDay) - 1;
    if ($weekStartsSunday) {
      $firstDayOfWeek = ($firstDayOfWeek + 1) % 7;
    }
    $today = strtotime(date('Y-m-d'));
    $todayDay = (int) date('j');
    $todayMonth = date('m');
    $todayYear = date('Y');

    // Project every payment occurrence into this month once, before rendering.
    $monthKey = $calendarYear . '-' . str_pad($calendarMonth, 2, '0', STR_PAD_LEFT);
    $startOfMonth = strtotime($monthKey . '-01');
    $paymentsByDay = [];

    $calendarExcludedCategoryNames = ['Credit Card'];
    $calendarExcludedCategoryIds = [];
    if (!empty($calendarExcludedCategoryNames)) {
        $placeholders = implode(',', array_fill(0, count($calendarExcludedCategoryNames), '?'));
        $excludeCatStmt = $db->prepare("SELECT id FROM categories WHERE user_id = ? AND name IN ($placeholders)");
        $excludeCatStmt->bindValue(1, $userId, SQLITE3_INTEGER);
        foreach ($calendarExcludedCategoryNames as $i => $name) {
            $excludeCatStmt->bindValue($i + 2, $name, SQLITE3_TEXT);
        }
        $excludeCatResult = $excludeCatStmt->execute();
        while ($excludeCatRow = $excludeCatResult->fetchArray(SQLITE3_ASSOC)) {
            $calendarExcludedCategoryIds[] = (int) $excludeCatRow['id'];
        }
    }

    $registerPayment = function ($date, $subscription) use (&$paymentsByDay, &$totalCostThisMonth, &$numberOfSubscriptionsToPayThisMonth, &$amountDueThisMonth, $today, $db, $userId, $calendarExcludedCategoryIds) {
      $paymentsByDay[(int) date('j', $date)][] = $subscription;

      $isExcludedFromBudget = isset($subscription['category_id']) && in_array((int) $subscription['category_id'], $calendarExcludedCategoryIds, true);

      $convertedPrice = getPriceConverted($subscription['price'], $subscription['currency_id'], $db, $userId);
      $numberOfSubscriptionsToPayThisMonth++;

      if (!$isExcludedFromBudget) {
        $totalCostThisMonth += $convertedPrice;
        if ($date >= $today) {
          $amountDueThisMonth += $convertedPrice;
        }
      }
    };

    foreach ($subscriptions as $subscription) {
      $nextPaymentDate = strtotime($subscription['next_payment']);
      $subscriptionStartDate = !empty($subscription['start_date'])
        ? strtotime($subscription['start_date'])
        : $nextPaymentDate;
      $cycle = $subscription['cycle'];
      $frequency = $subscription['frequency'];

      if ($cycle == 5) {
        // One-time purchase: only shown on its exact payment date
        if (date('Y-m', $nextPaymentDate) == $monthKey) {
          $registerPayment($nextPaymentDate, $subscription);
        }
        continue;
      }

      switch ($cycle) {
        case 1: // Days
          $incrementString = "+{$frequency} days";
          break;
        case 2: // Weeks
          $incrementString = "+{$frequency} weeks";
          break;
        case 3: // Months
          $incrementString = "+{$frequency} months";
          break;
        case 4: // Years
          $incrementString = "+{$frequency} years";
          break;
        default:
          $incrementString = "+{$frequency} months";
      }

      $endDate = strtotime("+" . $yearsToLoad . " years", $nextPaymentDate);

      // Find the first payment date of the month by moving backwards
      $startDate = $nextPaymentDate;
      while ($startDate > $startOfMonth) {
        $startDate = strtotime("-" . $incrementString, $startDate);
      }

      for ($date = $startDate; $date <= $endDate; $date = strtotime($incrementString, $date)) {
        if ($date < $subscriptionStartDate) {
          continue;
        }
        if (date('Y-m', $date) == $monthKey) {
          $registerPayment($date, $subscription);
        }
      }
    }
    ?>

    <div class="calendar">
      <div class="calendar-header">
        <?php foreach ($weekDays as $weekDay) { ?>
          <div class="calendar-cell"><?= translate($weekDay['key'], $i18n) ?></div>
        <?php } ?>
      </div>
      <div class="calendar-body">
        <div class="week calendar-row">
          <?php
          $dayOfWeek = 0;
          for ($i = 0; $i < $firstDayOfWeek; $i++) {
            echo '<div class="calendar-cell empty"></div>';
            $dayOfWeek++;
          }
          for ($day = 1; $day <= $daysInMonth; $day++) {
            if ($dayOfWeek > 0 && $dayOfWeek % 7 == 0) {
              echo '</div><div class="week calendar-row">';
            }
            $isToday = $day == $todayDay && $calendarMonth == $todayMonth && $calendarYear == $todayYear;
            ?>
            <div class="calendar-cell<?= $isToday ? ' today' : '' ?>">
              <span class="day"><?= $day ?></span>
              <?php if (!empty($paymentsByDay[$day])) { ?>
                <div class="calendar-cell-content">
                  <?php foreach ($paymentsByDay[$day] as $payment) { ?>
                    <?php
                      $eventTimestamp = strtotime(sprintf('%04d-%02d-%02d', $calendarYear, $calendarMonth, $day));
                      $daysUntilDue = (int) round(($eventTimestamp - $today) / 86400);

                      // paid_at, and "urgency", only ever describe the ONE live cycle
                      // Wallos is currently tracking (subscription's stored next_payment).
                      // Every other rendered occurrence is just a projection of past or
                      // further-future recurrences and shouldn't be colored at all.
                      $isActualNextPayment = date('Y-m-d', strtotime($payment['next_payment'])) === date('Y-m-d', $eventTimestamp);

                      $urgencyClass = '';
                      $urgencyLabel = '';

                      if ($isActualNextPayment) {
                        $eventIsPaid = isPaidThisCycle($payment['paid_at'] ?? null, $payment['cycle'], $payment['frequency'], $payment['next_payment']);

                        if ($eventIsPaid) {
                          $urgencyClass = 'paid';
                          $urgencyLabel = ' (' . translate('paid', $i18n) . ')';
                        } elseif ($daysUntilDue <= 3) {
                          $urgencyClass = 'due-urgent';
                        } elseif ($daysUntilDue <= 10) {
                          $urgencyClass = 'due-soon';
                        }
                      } elseif ($eventTimestamp < $today) {
                        $urgencyClass = 'past';
                      }
                    ?>

                    <div class="calendar-event<?= $urgencyClass ? ' ' . $urgencyClass : '' ?>" onClick="showSubscriptionDetails(event, <?= $payment['id'] ?>)"
                      title="<?= htmlspecialchars($payment['name']) . $urgencyLabel ?>">
                      <?= htmlspecialchars($payment['name']) ?>
                    </div>
                  <?php } ?>
                </div>
              <?php } ?>
            </div>
            <?php
            $dayOfWeek++;
          }
          while ($dayOfWeek % 7 != 0) {
            echo '<div class="calendar-cell empty"></div>';
            $dayOfWeek++;
          }
          ?>
        </div>
      </div>
    </div>

    <?php
      if ($budget > 0 && $totalCostThisMonth > $budget) {
        $overBudgetAmount = $totalCostThisMonth - $budget;
        $overBudgetAmount = CurrencyFormatter::format($overBudgetAmount, $code);
        ?>
          <div class="over-budget">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span><?= translate('over_budget_warning', $i18n) ?> <strong>(<?= $overBudgetAmount ?>)</strong></span>
          </div>
        <?php
      }
    ?>    

    <div class="calendar-monthly-stats">
      <div class="calendar-monthly-stats-header">
        <h3><?= translate("stats", $i18n) ?></h3>
      </div>
      <div class="statistics">
        <div class="statistic">
          <span>
            <?= $numberOfSubscriptionsToPayThisMonth ?></span>
          <div class="title"><?= translate("active_subscriptions", $i18n) ?></div>
        </div>
        <div class="statistic">
          <span><?= CurrencyFormatter::format($totalCostThisMonth, $code) ?></span>
          <div class="title"><?= translate("total_cost", $i18n) ?></div>
        </div>
        <div class="statistic">
          <span><?= CurrencyFormatter::format($amountDueThisMonth, $code) ?></span>
          <div class="title"><?= translate("amount_due", $i18n) ?></div>
        </div>
      </div>
    </div>

</section>

<?php require_once 'includes/subscription_details_popup.php'; ?>
<script src="scripts/calendar.js?<?= $version ?>"></script>
<?php
require_once 'includes/footer.php';
?>
