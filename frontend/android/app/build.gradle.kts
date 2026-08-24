import java.util.Properties
import java.io.FileInputStream

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// P3-13: release signing. `key.properties` is never committed (see
// android/.gitignore) — CI, local `flutter run --release`, and any machine
// without a real keystore all fall through to debug signing below, exactly
// like before this file was touched. A real production AAB must be built on
// a machine that has `key.properties` + the keystore file it points at
// (KEYSTORE_REQUIRED — see docs/EXTERNAL_ACCOUNTS.md); this repo does not
// and must not contain either.
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
val hasReleaseKeystore = keystorePropertiesFile.exists()
if (hasReleaseKeystore) {
    keystoreProperties.load(FileInputStream(keystorePropertiesFile))
}

android {
    // P3-13: real ARUCAD identity, mirroring the iOS bundle id already set
    // in ios/Runner.xcodeproj/project.pbxproj (`com.arucad.arucadCampusPrototype`)
    // so both stores list the same product identity. Confirm with ARUCAD
    // before shipping if this exact string wasn't already agreed — see
    // docs/EXTERNAL_ACCOUNTS.md §7.
    namespace = "com.arucad.arucadCampusPrototype"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        isCoreLibraryDesugaringEnabled = true
    }

    defaultConfig {
        applicationId = "com.arucad.arucadCampusPrototype"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
        // Microsoft Entra OAuth redirect scheme (flutter_appauth manifest
        // placeholder) — intentionally NOT tied to applicationId, matching
        // the same decoupling already present on iOS
        // (ios/Runner/Info.plist's CFBundleURLTypes vs. its bundle id).
        // Changing this requires re-registering the redirect URI in Azure
        // (docs/EXTERNAL_ACCOUNTS.md §1) — out of scope for this milestone.
        manifestPlaceholders["appAuthRedirectScheme"] = "com.example.arucad_campus_prototype"
    }

    signingConfigs {
        if (hasReleaseKeystore) {
            create("release") {
                storeFile = file(keystoreProperties.getProperty("storeFile"))
                storePassword = keystoreProperties.getProperty("storePassword")
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            // Real keystore present (key.properties + the file it points at)
            // -> sign for real. Otherwise fall back to the debug keys, same
            // as before this change, so `flutter run --release` and CI
            // builds keep working without a production keystore.
            signingConfig = if (hasReleaseKeystore) {
                signingConfigs.getByName("release")
            } else {
                signingConfigs.getByName("debug")
            }
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}
