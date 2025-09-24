// Optional wiring helpers.
// If you already have runtime getters in your codebase, call ThresholdSupervisor::setGetters(...) in setup().
// Alternatively, you may override the weak functions below in any translation unit to avoid touching setup():
//
//   extern "C" float SUP_getWindLowKmh()    { return Settings::wind_low_kmh(); }
//   extern "C" float SUP_getWindHighKmh()   { return Settings::wind_high_kmh(); }
//   extern "C" float SUP_getFlammeThreshold(){ return Settings::flame_threshold(); }
//   extern "C" uint32_t SUP_getNowEpoch()   { return Time::now_epoch(); }
//   extern "C" float SUP_getFlammeValue()   { return State::flame_value(); }
//
// This file provides no code on purpose, only documentation comments.
