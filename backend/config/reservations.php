<?php

return [
  'min_lead_minutes' => (int) env('RESERVATION_MIN_LEAD_MINUTES', 0),
  'cancel_refund_minutes' => (int) env('RESERVATION_CANCEL_REFUND_MINUTES', 60),
  'slot_step_minutes' => (int) env('RESERVATION_SLOT_STEP_MINUTES', 15),
];
