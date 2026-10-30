#!/usr/bin/env bash
# Sayfalardaki canli veriyi tazelemesi icin siteyi durtur (api/canli-tazele.php).
#
# Ajan is akisi (her calismada) ve "Canli veri" is akisi (gun icinde iki
# saatte bir) bunu cagiriyor. Veriyi site cekiyor; bolumler ayri istek,
# biri dusse otekiler yine deneniyor. Basarisiz istek isi kirmizi yapmiyor:
# sitedeki son iyi veri kaliyor, bir sonraki calisma yeniden deniyor.
#
# Gereken ortam degiskenleri: VALENTRA_SITE_URL, VALENTRA_AGENT_KEY.

set -u

if [ -z "${VALENTRA_SITE_URL:-}" ] || [ -z "${VALENTRA_AGENT_KEY:-}" ]; then
  echo "::warning::VALENTRA_SITE_URL ya da VALENTRA_AGENT_KEY tanimli degil; canli veri tazelenmedi."
  exit 0
fi

# ozgun en sonda: digerlerinin tazeledigi veriden haber yaziyor.
for bolum in piyasa spor gostergeler resmi-gazete ozgun sirket; do
  echo "== $bolum"

  if yanit=$(curl -sS --fail-with-body --max-time 120 -X POST \
      -H "X-Valentra-Key: $VALENTRA_AGENT_KEY" \
      -H "Authorization: Bearer $VALENTRA_AGENT_KEY" \
      "${VALENTRA_SITE_URL%/}/api/canli-tazele.php?bolum=$bolum"); then
    echo "$yanit"
  else
    echo "$yanit"
    echo "::warning::Canli veri ($bolum) tazelenemedi; sitedeki son veri korunuyor, sayfa acilinca da tazelenir."
  fi

  echo
done

exit 0
