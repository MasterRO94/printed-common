# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.4]
### Fixed
- [ImageFileGeometryExtractor] (Potential breaking change) Orient the detected dimensions based on the jpeg's orientation feature.

## [0.2.3]
### Added
- [CpdfPdfSplitter] Add `timeoutSeconds` option
- [TemporaryFile] Clear fstat cache on different occasions

## [0.2.2]
### Added
- [PdfPreviewGenerator] `withTransparency` option: allows rendering pngs with transparency.

## [0.2.1]
### Fixed
- [CpdfPdfSplitter] Fix a regression defect.

## [0.2.0]
### Changed
- [CpdfPdfSplitter] `maxPageCount` option: limits the number of split pages.
- (minor breaking change) CpdfPdfSplitter requires more constructor args. 

## [0.1.0]
### Changed
- A full path to the CPDF binary is now required. This is a breaking change.
