#!/bin/bash
set -euo pipefail

# Résolution des chemins par rapport à l'emplacement du script (dossier dev/)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
OUTPUT_DIR="$SCRIPT_DIR"

# Nom du module (tout en minuscules, requis par Dolibarr à la racine du zip)
MODULE_NAME="qontosync"

# 1. Vérification des prérequis et du dossier source
if ! command -v zip >/dev/null 2>&1; then
    echo "❌ Erreur : La commande 'zip' est introuvable."
    exit 1
fi

if [ ! -d "$SRC_DIR" ]; then
    echo "❌ Erreur : Le dossier source '$SRC_DIR' est introuvable."
    exit 1
fi

# 2. Chemin vers le fichier de classe principal pour extraire la version
CLASS_FILE="$SRC_DIR/core/modules/modQontosync.class.php"

if [ ! -f "$CLASS_FILE" ]; then
    echo "❌ Erreur : Le fichier $CLASS_FILE est introuvable."
    exit 1
fi

# 3. Extraction de la version (cherche la ligne $this->version = 'X.X';)
VERSION="$(grep -m1 '\$this->version' "$CLASS_FILE" | awk -F"['\"]" '{print $2}' || true)"

if [ -z "$VERSION" ]; then
    echo "❌ Erreur : Impossible de lire la version dans le fichier $CLASS_FILE."
    exit 1
fi

echo "✅ Version détectée : $VERSION"

# Nom et chemin de l'archive finale
ARCHIVE_NAME="module_${MODULE_NAME}-${VERSION}.zip"
ARCHIVE_PATH="$OUTPUT_DIR/$ARCHIVE_NAME"

# Dossier de travail temporaire isolé (hors de SRC_DIR) et nettoyé automatiquement
BUILD_DIR="$(mktemp -d -t "build_${MODULE_NAME}_XXXXXX")"
trap 'rm -rf "$BUILD_DIR"' EXIT
TARGET_DIR="$BUILD_DIR/$MODULE_NAME"

# 4. Préparation du dossier temporaire
echo "⚙️  Préparation du dossier de build..."
cp -r "$SRC_DIR" "$TARGET_DIR"

# 5. Nettoyage des fichiers de développement dans la copie temporaire
echo "🧹 Suppression des fichiers parasites..."
rm -rf "$TARGET_DIR/.git"
rm -f "$TARGET_DIR/.gitignore"
rm -rf "$TARGET_DIR/dev"
rm -f "$TARGET_DIR/module_${MODULE_NAME}-"*.zip
find "$TARGET_DIR" -name ".DS_Store" -type f -delete 2>/dev/null || true
find "$TARGET_DIR" -name "Thumbs.db" -type f -delete 2>/dev/null || true

# 6. Création de l'archive Zip depuis le dossier temporaire
echo "📦 Création de l'archive $ARCHIVE_NAME..."
rm -f "$ARCHIVE_PATH"
(cd "$BUILD_DIR" && zip -rq "$ARCHIVE_PATH" "$MODULE_NAME")

echo "🎉 Terminé ! L'archive est prête et valide pour Dolibarr : $ARCHIVE_PATH"
