#include "utils.h"
#include "config.h"
#include "settings.h" // Ajout
#include "state.h"
#include "data_logging.h"
#include <cmath>
#include <stdio.h>

namespace Utils {

int daysInMonth(int y, int m) {
    static const int dpm[12] = {31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31};
    if (m < 1 || m > 12) return 0;
    int d = dpm[m - 1];
    if (m == 2) {
        bool leap = (y % 4 == 0 && (y % 100 != 0 || y % 400 == 0));
        if (leap) d = 29;
    }
    return d;
}

double degToRad(double degrees) { return degrees * M_PI / 180.0; }
double radToDeg(double radians) { return radians * 180.0 / M_PI; }

int getDayOfYear(int year, int month, int day) {
  static const int daysUntilMonth[] = {0,31,59,90,120,151,181,212,243,273,304,334};
  int dayOfYear = daysUntilMonth[month-1] + day;
  bool leap = (year%4==0 && (year%100!=0 || year%400==0));
  if(leap && month>2) dayOfYear++;
  return dayOfYear;
}

bool isDaylightSaving(struct tm* timeinfo){
  int month=timeinfo->tm_mon+1, day=timeinfo->tm_mday, dow=timeinfo->tm_wday;
  if(month<3||month>10) return false;
  if(month>3&&month<10) return true;
  int lastSunday=day-dow;
  if(month==3) return(lastSunday>=25);
  if(month==10) return(lastSunday<25);
  return false;
}

void calculateSolarTimes() {
  struct tm timeinfo;
  if(!getLocalTime(&timeinfo)){ DataLogging::writeLog(LogLevel::LOG_ERROR,"No time for solar calc"); return; }
  int year=timeinfo.tm_year+1900, month=timeinfo.tm_mon+1, day=timeinfo.tm_mday;
  int dayOfYear=getDayOfYear(year,month,day);
  if(g_solarTimes.isValid && g_solarTimes.dayOfYear==dayOfYear) return;

  DataLogging::writeLog(LogLevel::LOG_INFO,"Calculating solar times for day "+String(dayOfYear));
  
  // CORRECTION : Utilisation des paramètres de SETTINGS
  double lngHour = SETTINGS.longitude / 15.0;
  double t_rise = dayOfYear + (6.0 - lngHour) / 24.0;
  double t_set = dayOfYear + (18.0 - lngHour) / 24.0;
  double tz = isDaylightSaving(&timeinfo) ? 2.0 : 1.0;

  auto compute = [&](double t, bool isRise, double tz_offset, double lngHour_offset) -> double {
    double M = 0.9856 * t - 3.289;
    double L = M + 1.916 * sin(degToRad(M)) + 0.020 * sin(degToRad(2 * M)) + 282.634;
    L = fmod(L + 360, 360);
    double RA = radToDeg(atan(0.91764 * tan(degToRad(L))));
    RA = fmod(RA + 360, 360);
    double Lquad = floor(L / 90.0) * 90.0;
    double RAquad = floor(RA / 90.0) * 90.0;
    RA += (Lquad - RAquad);
    RA /= 15.0;
    double sinDec = 0.39782 * sin(degToRad(L));
    double cosDec = cos(asin(sinDec));
    double cosH = (cos(degToRad(90.833)) - (sinDec * sin(degToRad(SETTINGS.latitude)))) / (cosDec * cos(degToRad(SETTINGS.latitude)));
    if (cosH > 1 || cosH < -1) return -1.0;
    double H = radToDeg(acos(cosH));
    if (isRise) H = 360.0 - H;
    H /= 15.0;
    double T = H + RA - (0.06571 * t) - 6.622;
    double UT = T - lngHour_offset;
    UT = fmod(UT + 24, 24);
    return fmod(UT + tz_offset + 24, 24);
  };

  double sunriseTime = compute(t_rise, true, tz, lngHour);
  double sunsetTime = compute(t_set, false, tz, lngHour);

  if(sunriseTime < 0 || sunsetTime < 0){
    g_solarTimes.sunriseHour = 6; g_solarTimes.sunriseMinute = 0;
    g_solarTimes.sunsetHour = 18; g_solarTimes.sunsetMinute = 0;
  } else {
    g_solarTimes.sunriseHour = (int)sunriseTime;
    g_solarTimes.sunriseMinute = (int)((sunriseTime - g_solarTimes.sunriseHour) * 60);
    g_solarTimes.sunsetHour = (int)sunsetTime;
    g_solarTimes.sunsetMinute = (int)((sunsetTime - g_solarTimes.sunsetHour) * 60);
  }
  g_solarTimes.dayOfYear = dayOfYear;
  g_solarTimes.isValid = true;
  char riseBuf[6]; snprintf(riseBuf, sizeof(riseBuf), "%d:%02d", g_solarTimes.sunriseHour, g_solarTimes.sunriseMinute);
  char setBuf[6]; snprintf(setBuf, sizeof(setBuf), "%d:%02d", g_solarTimes.sunsetHour, g_solarTimes.sunsetMinute);
  DataLogging::writeLog(LogLevel::LOG_INFO, String("Solar times: Rise ") + String(riseBuf) + ", Set " + String(setBuf));
}

bool isDaytimeByAstronomy() {
    if(!g_solarTimes.isValid) return true;
    struct tm timeinfo;
    if(!getLocalTime(&timeinfo)) return true;
    int currentMinutes = timeinfo.tm_hour*60 + timeinfo.tm_min;
    int sunriseMinutes = g_solarTimes.sunriseHour*60 + g_solarTimes.sunriseMinute;
    int sunsetMinutes = g_solarTimes.sunsetHour*60 + g_solarTimes.sunsetMinute;
    return (currentMinutes >= sunriseMinutes && currentMinutes < sunsetMinutes);
}

String formatShelly3Chars(float watts) {
    if (watts < 0) watts = 0;
    if (watts < 1000.0f) {
        char buf[5];
        snprintf(buf, sizeof(buf), "%d", (int)roundf(watts));
        return String(buf);
    } else {
        float kW = watts / 1000.0f;
        return String(kW, 1); 
    }
}

String formatTemperature(float temp) { return String(temp, 1); }

bool isValidTemperature(float temp, const String& source) {
    if (isnan(temp) || isinf(temp)) { 
        DataLogging::writeLog(LogLevel::LOG_WARN, source + " invalid temp");
        return false;
    }
    if (temp < -20 || temp > 60) {
        DataLogging::writeLog(LogLevel::LOG_WARN, source + " temp out of range: " + String(temp));
        return false;
    }
    return true;
}

} // namespace Utils