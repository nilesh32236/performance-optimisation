#!/bin/bash
# Remove aria-hidden="true" from FontAwesomeIcon usages specifically within the icon prop of FeatureCard and related components.

find src/components -type f -name "*.js" -print0 | xargs -0 perl -pi -e 's/icon=\{\s*<\s*FontAwesomeIcon([^>]*)aria-hidden="true"([^>]*)>\s*\}/icon={<FontAwesomeIcon$1$2>}/g'
