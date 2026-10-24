const fs = require('node:fs');
const path = require('node:path');
const { withDangerousMod, withInfoPlist, withXcodeProject } = require('@expo/config-plugins');

const fontFiles = [
  'plus-jakarta-400.ttf',
  'plus-jakarta-500.ttf',
  'plus-jakarta-600.ttf',
  'plus-jakarta-700.ttf',
  'plus-jakarta-800.ttf',
];

module.exports = function withPlusJakartaFonts(config) {
  config = withInfoPlist(config, (config) => {
    const existing = config.modResults.UIAppFonts ?? [];
    config.modResults.UIAppFonts = [...new Set([...existing, ...fontFiles])];
    return config;
  });

  config = withXcodeProject(config, (config) => {
    const project = config.modResults;
    const target = project.getFirstTarget().uuid;
    fontFiles.forEach((file) => project.addResourceFile(`Fonts/${file}`, { target }));
    return config;
  });

  config = withDangerousMod(config, ['android', async (config) => {
    const source = path.join(config.modRequest.projectRoot, 'assets/fonts');
    const destination = path.join(config.modRequest.platformProjectRoot, 'app/src/main/assets/fonts');
    fs.mkdirSync(destination, { recursive: true });
    fontFiles.forEach((file) => fs.copyFileSync(path.join(source, file), path.join(destination, file)));
    return config;
  }]);

  return withDangerousMod(config, ['ios', async (config) => {
    const source = path.join(config.modRequest.projectRoot, 'assets/fonts');
    const destination = path.join(config.modRequest.platformProjectRoot, config.modRequest.projectName, 'Fonts');
    fs.mkdirSync(destination, { recursive: true });
    fontFiles.forEach((file) => fs.copyFileSync(path.join(source, file), path.join(destination, file)));
    return config;
  }]);
};
