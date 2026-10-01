# LeadFlow mobile app (iOS & Android)

A native shell around the LeadFlow web app built with [Capacitor](https://capacitorjs.com).
Everything in the web app works the same; the shell adds an App Store / Play Store
install and **native push notifications** through Firebase Cloud Messaging.

> The installable web app (PWA) already gets push on Android, desktop and iPhone
> (iOS 16.4+, after "Add to Home Screen"). Build this shell when you want store
> distribution.

## 1. Point the app at your server

The shell bundles the web app, so it needs the full API address at build time:

```bash
cd frontend
echo "VITE_API_URL=https://crm.yourcompany.com/api/v1" > .env.production.local
```

## 2. Create the native projects (once)

Requires Node 20+, Android Studio and/or Xcode.

```bash
cd mobile
npm install
npx cap add android
npx cap add ios
```

## 3. Push notifications

1. Create a Firebase project and add an Android app (`com.leadflow.app`) and an iOS app with the same id.
2. Download `google-services.json` to `mobile/android/app/` and `GoogleService-Info.plist` to `mobile/ios/App/App/`.
3. For iOS, upload your APNs key in Firebase → Project settings → Cloud Messaging, and enable the
   *Push Notifications* capability in Xcode.
4. On the server, create a service account key (Firebase → Project settings → Service accounts) and set
   `FCM_CREDENTIALS` to the JSON (or a path to the file) in `backend/.env`.

In the app, people turn push on under **Profile → Notifications → Push notifications on this device**.
The app registers its FCM token with `POST /api/v1/push/subscriptions`, and tapping a notification
opens the related page.

## 4. Run

```bash
npm run android   # builds the web app, syncs, opens Android Studio
npm run ios       # same for Xcode
```
