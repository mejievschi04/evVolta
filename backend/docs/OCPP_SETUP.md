# Volta EV OCPP setup

This project is prepared for real charging stations through an OCPP gateway.

## Runtime pieces

- Mobile app and backoffice call the Laravel API.
- Charging stations connect to the OCPP WebSocket gateway.
- The gateway updates `stations`, `charging_sessions`, `ocpp_messages`, and `ocpp_commands`.

## Local gateway

Run the normal Laravel API:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Run the OCPP gateway in a second terminal:

```powershell
php artisan ocpp:serve --host=0.0.0.0 --port=9000
```

For production, run this command under a process manager and expose it through TLS:

```text
wss://ocpp.volta.md/ocpp/{ocpp_identity}
```

## Environment

```dotenv
OCPP_MODE=gateway
OCPP_HOST=0.0.0.0
OCPP_PORT=9000
OCPP_PUBLIC_URL=wss://ocpp.volta.md/ocpp
OCPP_HEARTBEAT_INTERVAL=60
```

Keep `OCPP_MODE=simulator` for demos without real hardware.

## Station configuration

In backoffice, each station has:

- `OCPP identity`: the charge point identity/serial configured in the charger.
- `OCPP version`: start with `1.6J` unless the vendor confirms `2.0.1`.
- `URL conectare statie`: copy this into the charger OCPP backend URL.

Example:

```text
OCPP identity: volta-station-01
Charger URL: wss://ocpp.volta.md/ocpp/volta-station-01
Protocol: OCPP 1.6 JSON over WebSocket
Heartbeat interval: 60 seconds
```

## Supported OCPP 1.6J messages

The gateway currently handles the production-critical base flow:

- `BootNotification`
- `Heartbeat`
- `StatusNotification`
- `Authorize`
- `StartTransaction`
- `MeterValues`
- `StopTransaction`
- outbound `RemoteStartTransaction`
- outbound `RemoteStopTransaction`

## Real billing rule

For real stations, invoices should use meter values from the charger:

- `meter_start_kwh`
- `meter_stop_kwh`
- `kwh_consumed`

The old time-based calculation is still kept for simulator mode.

## Hardware acceptance checklist

Before accepting a physical station:

1. Configure the station URL from backoffice.
2. Confirm it sends `BootNotification`.
3. Confirm heartbeat updates `last_heartbeat_at`.
4. Plug in a car and confirm `StatusNotification = Preparing/Charging`.
5. Start from mobile and confirm an OCPP command is created and sent.
6. Confirm `MeterValues` update `meter_value_kwh`.
7. Stop charging and confirm `StopTransaction` closes the session.
8. Confirm invoice uses the measured kWh.
